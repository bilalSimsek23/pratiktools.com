<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F7: two-person approvals, controlled (test-only) dispatch requests and an
 * in-app notification inbox. Approvals bind to a campaign fingerprint
 * (audience snapshot, content version, sender, integration); a decided
 * approval never changes except to expire or be invalidated.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tenant_users DROP CONSTRAINT tenant_users_role_check');
        DB::statement("ALTER TABLE tenant_users ADD CONSTRAINT tenant_users_role_check CHECK (role IN ('owner', 'admin', 'marketer', 'viewer', 'approver'))");

        // F6 trigger, extended: a READY campaign may be reopened (back to draft,
        // unfrozen) — that is the only way content, audience or sender change,
        // and it invalidates every approval of the old fingerprint.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION campaign_frozen_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.frozen_at IS NOT NULL AND OLD.status = 'ready' AND NEW.status = 'draft' AND NEW.frozen_at IS NULL THEN
                    RETURN NEW;
                END IF;
                IF OLD.frozen_at IS NOT NULL AND (NEW.segment_snapshot_id IS DISTINCT FROM OLD.segment_snapshot_id OR NEW.template_version_id IS DISTINCT FROM OLD.template_version_id
                    OR NEW.sender_identity_id IS DISTINCT FROM OLD.sender_identity_id OR NEW.integration_id IS DISTINCT FROM OLD.integration_id
                    OR NEW.segment_id IS DISTINCT FROM OLD.segment_id OR NEW.audience_source IS DISTINCT FROM OLD.audience_source
                    OR NEW.type IS DISTINCT FROM OLD.type OR NEW.frozen_at IS DISTINCT FROM OLD.frozen_at) THEN
                    RAISE EXCEPTION 'campaign % is frozen: audience, content and sender are immutable', OLD.id USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);

        Schema::create('campaign_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('campaign_id');
            $table->char('fingerprint', 64);
            // review | approve (two distinct people)
            $table->string('slot', 16);
            $table->uuid('requested_by');
            $table->uuid('decided_by')->nullable();
            // pending | approved | rejected | expired | invalidated
            $table->string('status', 16)->default('pending');
            $table->string('reason', 500)->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 48)->nullable();

            $table->foreign(['tenant_id', 'campaign_id'])->references(['tenant_id', 'id'])->on('campaigns')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'campaign_id', 'status']);
            $table->index(['tenant_id', 'status', 'expires_at']);
        });
        DB::statement("ALTER TABLE campaign_approvals ADD CONSTRAINT campaign_approvals_check CHECK (slot IN ('review', 'approve')
            AND status IN ('pending', 'approved', 'rejected', 'expired', 'invalidated') AND (decided_by IS NULL OR decided_by <> requested_by))");
        // One open (pending or approved) approval per slot and fingerprint.
        DB::statement("CREATE UNIQUE INDEX campaign_approvals_one_open ON campaign_approvals (tenant_id, campaign_id, fingerprint, slot) WHERE status IN ('pending', 'approved')");
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION campaign_approval_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.campaign_id IS DISTINCT FROM OLD.campaign_id OR NEW.fingerprint IS DISTINCT FROM OLD.fingerprint OR NEW.slot IS DISTINCT FROM OLD.slot
                    OR NEW.requested_by IS DISTINCT FROM OLD.requested_by OR NEW.requested_at IS DISTINCT FROM OLD.requested_at THEN
                    RAISE EXCEPTION 'approval % identity is immutable', OLD.id USING ERRCODE = 'P0001';
                END IF;
                IF OLD.status = 'pending' AND NEW.status IN ('approved', 'rejected', 'expired', 'invalidated', 'pending') THEN
                    RETURN NEW;
                END IF;
                IF OLD.status = 'approved' AND NEW.status IN ('expired', 'invalidated') AND NEW.decided_by IS NOT DISTINCT FROM OLD.decided_by
                    AND NEW.decided_at IS NOT DISTINCT FROM OLD.decided_at AND NEW.reason IS NOT DISTINCT FROM OLD.reason THEN
                    RETURN NEW;
                END IF;
                IF NEW IS NOT DISTINCT FROM OLD THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'approval % is decided (%) and immutable', OLD.id, OLD.status USING ERRCODE = 'P0001';
            END $$
        SQL);
        DB::statement('CREATE TRIGGER campaign_approvals_immutable BEFORE UPDATE ON campaign_approvals FOR EACH ROW EXECUTE FUNCTION campaign_approval_immutable()');

        Schema::create('campaign_dispatches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('campaign_id');
            $table->uuid('segment_snapshot_id');
            $table->char('fingerprint', 64);
            // email | push
            $table->string('channel', 8);
            // test_only | live (live is refused in F7)
            $table->string('mode', 16);
            $table->unsignedInteger('generation');
            $table->char('idempotency_key', 64);
            // draft | awaiting_approval | approved | queued | running | completed | failed | cancelled | blocked
            $table->string('status', 20)->default('draft');
            $table->uuid('requested_by');
            // Test recipients chosen from the canonical allowlist (ids only).
            $table->jsonb('recipients')->default('[]');
            $table->jsonb('approvals')->default('[]');
            $table->jsonb('plan')->default('{}');
            $table->jsonb('content')->default('{}');
            $table->jsonb('counters')->default('{}');
            $table->string('block_reason', 64)->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'campaign_id'])->references(['tenant_id', 'id'])->on('campaigns')->cascadeOnDelete();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'campaign_id', 'segment_snapshot_id', 'channel', 'mode', 'generation']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE campaign_dispatches ADD CONSTRAINT campaign_dispatches_check CHECK (channel IN ('email', 'push') AND mode IN ('test_only', 'live')
            AND status IN ('draft', 'awaiting_approval', 'approved', 'queued', 'running', 'completed', 'failed', 'cancelled', 'blocked'))");
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION campaign_dispatch_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.campaign_id IS DISTINCT FROM OLD.campaign_id OR NEW.segment_snapshot_id IS DISTINCT FROM OLD.segment_snapshot_id OR NEW.fingerprint IS DISTINCT FROM OLD.fingerprint
                    OR NEW.channel IS DISTINCT FROM OLD.channel OR NEW.mode IS DISTINCT FROM OLD.mode OR NEW.generation IS DISTINCT FROM OLD.generation
                    OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key OR NEW.requested_by IS DISTINCT FROM OLD.requested_by
                    OR NEW.recipients IS DISTINCT FROM OLD.recipients OR NEW.content IS DISTINCT FROM OLD.content THEN
                    RAISE EXCEPTION 'dispatch request % is immutable', OLD.id USING ERRCODE = 'P0001';
                END IF;
                IF OLD.status IN ('cancelled', 'completed') AND NEW.status IS DISTINCT FROM OLD.status THEN
                    RAISE EXCEPTION 'dispatch request % is % and final', OLD.id, OLD.status USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER campaign_dispatches_immutable BEFORE UPDATE ON campaign_dispatches FOR EACH ROW EXECUTE FUNCTION campaign_dispatch_immutable()');

        Schema::table('email_messages', function (Blueprint $table) {
            $table->uuid('dispatch_id')->nullable();
            $table->index(['tenant_id', 'dispatch_id']);
        });
        Schema::table('push_send_requests', function (Blueprint $table) {
            $table->uuid('dispatch_id')->nullable();
            $table->index(['tenant_id', 'dispatch_id']);
        });

        Schema::create('tenant_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            // Recipient user, or null for everyone holding the permission.
            $table->uuid('user_id')->nullable();
            $table->string('permission', 48)->nullable();
            $table->string('kind', 48);
            $table->string('subject_type', 32);
            $table->uuid('subject_id');
            $table->string('message', 300);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('read_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'read_at', 'created_at']);
        });

        foreach (['campaign_approvals', 'campaign_dispatches', 'tenant_notifications'] as $table) {
            Rls::isolateTenantTable($table);
            Rls::grantApp($table, ['SELECT', 'INSERT', 'UPDATE']);
        }
        Rls::grantPlatformRead('campaign_dispatches', ['id', 'tenant_id', 'channel', 'mode', 'status', 'requested_at']);
    }

    public function down(): void
    {
        foreach (['tenant_notifications', 'campaign_dispatches', 'campaign_approvals'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS campaign_dispatch_immutable()');
        DB::statement('DROP FUNCTION IF EXISTS campaign_approval_immutable()');
        foreach (['email_messages', 'push_send_requests'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropIndex(['tenant_id', 'dispatch_id']);
                $table->dropColumn('dispatch_id');
            });
        }
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION campaign_frozen_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.frozen_at IS NOT NULL AND (NEW.segment_snapshot_id IS DISTINCT FROM OLD.segment_snapshot_id OR NEW.template_version_id IS DISTINCT FROM OLD.template_version_id
                    OR NEW.sender_identity_id IS DISTINCT FROM OLD.sender_identity_id OR NEW.integration_id IS DISTINCT FROM OLD.integration_id
                    OR NEW.segment_id IS DISTINCT FROM OLD.segment_id OR NEW.audience_source IS DISTINCT FROM OLD.audience_source
                    OR NEW.type IS DISTINCT FROM OLD.type OR NEW.frozen_at IS DISTINCT FROM OLD.frozen_at) THEN
                    RAISE EXCEPTION 'campaign % is frozen: audience, content and sender are immutable', OLD.id USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement("UPDATE tenant_users SET role = 'viewer' WHERE role = 'approver'");
        DB::statement('ALTER TABLE tenant_users DROP CONSTRAINT tenant_users_role_check');
        DB::statement("ALTER TABLE tenant_users ADD CONSTRAINT tenant_users_role_check CHECK (role IN ('owner', 'admin', 'marketer', 'viewer'))");
    }
};
