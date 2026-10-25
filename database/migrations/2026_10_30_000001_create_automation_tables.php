<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Segmentation and automation (F5). Automations never send: an email/push
 * step only records a planned action (intent) that a later dispatcher may
 * use. Idempotency is enforced by the database:
 * - one run per (automation, trigger idempotency key);
 * - at most one active run per (automation, scope) (partial unique index);
 * - one planned action per idempotency key;
 * - one step row per (run, step key).
 * Published automation versions and segment snapshots are immutable
 * (trigger / no UPDATE grant).
 */
return new class extends Migration
{
    private const TABLES = [
        'segments', 'segment_snapshots', 'segment_snapshot_members', 'automations', 'automation_versions', 'automation_runs',
        'automation_run_steps', 'automation_planned_actions', 'automation_trigger_cursors', 'automation_metrics_daily',
    ];

    public function up(): void
    {
        Schema::create('segments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 120);
            // dynamic | snapshot
            $table->string('type', 16);
            $table->jsonb('definition');
            $table->string('status', 16)->default('active');
            $table->string('created_by', 64)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE segments ADD CONSTRAINT segments_type_check CHECK (type IN ('dynamic', 'snapshot'))");

        Schema::create('segment_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('segment_id');
            $table->char('definition_hash', 64);
            $table->jsonb('definition');
            $table->unsignedInteger('member_count');
            $table->string('created_by', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'segment_id'])->references(['tenant_id', 'id'])->on('segments')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('segment_snapshot_members', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('snapshot_id');
            $table->uuid('customer_id');

            $table->primary(['tenant_id', 'snapshot_id', 'customer_id']);
            $table->foreign(['tenant_id', 'snapshot_id'])->references(['tenant_id', 'id'])->on('segment_snapshots')->cascadeOnDelete();
        });

        Schema::create('automations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 120);
            $table->string('template', 32)->nullable();
            // draft | published | paused | archived
            $table->string('status', 16)->default('draft');
            $table->uuid('published_version_id')->nullable();
            $table->string('created_by', 64)->nullable();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE automations ADD CONSTRAINT automations_status_check CHECK (status IN ('draft', 'published', 'paused', 'archived'))");

        Schema::create('automation_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('automation_id');
            $table->unsignedInteger('version');
            $table->jsonb('trigger');
            $table->jsonb('steps');
            $table->jsonb('settings')->default('{}');
            $table->char('definition_hash', 64);
            // draft | published | superseded
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->string('created_by', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'automation_id'])->references(['tenant_id', 'id'])->on('automations')->cascadeOnDelete();
            $table->unique(['tenant_id', 'automation_id', 'version']);
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE automation_versions ADD CONSTRAINT automation_versions_status_check CHECK (status IN ('draft', 'published', 'superseded'))");
        // A version that was ever published keeps its definition forever.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION automation_version_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.published_at IS NOT NULL AND (NEW.trigger IS DISTINCT FROM OLD.trigger OR NEW.steps IS DISTINCT FROM OLD.steps
                    OR NEW.settings IS DISTINCT FROM OLD.settings OR NEW.definition_hash IS DISTINCT FROM OLD.definition_hash
                    OR NEW.version IS DISTINCT FROM OLD.version OR NEW.published_at IS DISTINCT FROM OLD.published_at) THEN
                    RAISE EXCEPTION 'automation version % is published and immutable', OLD.id USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER automation_versions_immutable BEFORE UPDATE ON automation_versions FOR EACH ROW EXECUTE FUNCTION automation_version_immutable()');

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('automation_id');
            $table->uuid('version_id');
            $table->string('trigger_type', 40);
            $table->string('trigger_ref', 191);
            $table->char('idempotency_key', 64);
            // What "one active run" means: customer:<id> or cart:<id>.
            $table->string('scope_key', 80);
            $table->uuid('customer_id')->nullable();
            // pending | running | waiting | completed | exited | cancelled | failed
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('current_step')->default(0);
            $table->timestamp('resume_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_until')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->jsonb('context')->default('{}');
            $table->string('exit_reason', 48)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('triggered_at');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'automation_id'])->references(['tenant_id', 'id'])->on('automations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'version_id'])->references(['tenant_id', 'id'])->on('automation_versions')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->nullOnDelete();
            $table->unique(['tenant_id', 'automation_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'resume_at']);
            $table->index(['tenant_id', 'automation_id', 'status']);
            $table->index(['tenant_id', 'customer_id']);
        });
        DB::statement("ALTER TABLE automation_runs ADD CONSTRAINT automation_runs_status_check CHECK (status IN ('pending', 'running', 'waiting', 'completed', 'exited', 'cancelled', 'failed'))");
        DB::statement("CREATE UNIQUE INDEX automation_runs_one_active ON automation_runs (tenant_id, automation_id, scope_key) WHERE status IN ('pending', 'running', 'waiting')");

        Schema::create('automation_run_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('run_id');
            $table->unsignedSmallInteger('step_index');
            $table->string('step_key', 48);
            $table->string('type', 32);
            // passed | waiting | failed_condition | blocked | intent_created | exited
            $table->string('outcome', 24);
            $table->string('reason', 48)->nullable();
            $table->timestamp('executed_at')->useCurrent();

            $table->foreign(['tenant_id', 'run_id'])->references(['tenant_id', 'id'])->on('automation_runs')->cascadeOnDelete();
            $table->unique(['tenant_id', 'run_id', 'step_key']);
        });

        Schema::create('automation_planned_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('run_id');
            $table->uuid('automation_id');
            $table->uuid('version_id');
            $table->string('step_key', 48);
            // email | push
            $table->string('channel', 8);
            // marketing | transactional
            $table->string('message_type', 16);
            $table->uuid('customer_id');
            // consent_recipients.id (email) or push_subscriptions.id (push)
            $table->uuid('recipient_ref');
            $table->char('idempotency_key', 64);
            // planned | cancelled (dispatch does not exist in F5)
            $table->string('status', 16)->default('planned');
            // References only (template, cart, products): no price, stock or PII.
            $table->jsonb('payload')->default('{}');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->foreign(['tenant_id', 'run_id'])->references(['tenant_id', 'id'])->on('automation_runs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->cascadeOnDelete();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'channel', 'message_type', 'customer_id', 'created_at']);
        });
        DB::statement("ALTER TABLE automation_planned_actions ADD CONSTRAINT automation_planned_actions_check CHECK (channel IN ('email', 'push') AND message_type IN ('marketing', 'transactional') AND status IN ('planned', 'cancelled'))");

        // Per-tenant read position in each trigger source (uuid7 / time ordered).
        Schema::create('automation_trigger_cursors', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->string('source', 32);
            $table->string('position', 120)->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->primary(['tenant_id', 'source']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        // Counters only (no personal data); the platform role reads them.
        Schema::create('automation_metrics_daily', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('automation_id');
            $table->date('day');
            foreach (['runs_started', 'runs_completed', 'runs_exited', 'runs_failed', 'runs_cancelled', 'planned_email', 'planned_push', 'consent_blocked',
                'suppression_blocked', 'frequency_capped', 'identity_blocked', 'duplicate_prevented', 'steps_processed'] as $counter) {
                $table->unsignedInteger($counter)->default(0);
            }
            $table->unsignedBigInteger('latency_ms_sum')->default(0);
            $table->unsignedInteger('latency_count')->default(0);

            $table->primary(['tenant_id', 'automation_id', 'day']);
            $table->foreign(['tenant_id', 'automation_id'])->references(['tenant_id', 'id'])->on('automations')->cascadeOnDelete();
        });

        foreach (self::TABLES as $table) {
            Rls::isolateTenantTable($table);
        }
        foreach (['segments', 'automations', 'automation_runs', 'automation_run_steps', 'automation_planned_actions', 'automation_trigger_cursors', 'automation_metrics_daily'] as $table) {
            Rls::grantApp($table, ['SELECT', 'INSERT', 'UPDATE']);
        }
        // Immutable once written.
        Rls::grantApp('segment_snapshots', ['SELECT', 'INSERT']);
        Rls::grantApp('segment_snapshot_members', ['SELECT', 'INSERT']);
        Rls::grantApp('automation_versions', ['SELECT', 'INSERT', 'UPDATE']);
        Rls::grantPlatformRead('automation_metrics_daily', ['tenant_id', 'automation_id', 'day', 'runs_started', 'runs_completed', 'runs_exited', 'runs_failed',
            'planned_email', 'planned_push', 'consent_blocked', 'suppression_blocked', 'frequency_capped', 'identity_blocked', 'duplicate_prevented']);
        Rls::grantPlatformRead('automations', ['id', 'tenant_id', 'status', 'template', 'created_at']);

        // Trigger scans walk sources by (tenant, id); uuid7 ids are time ordered.
        DB::statement('CREATE INDEX IF NOT EXISTS customer_events_tenant_id_cursor ON customer_events (tenant_id, id)');
        DB::statement('CREATE INDEX IF NOT EXISTS consent_events_tenant_id_cursor ON consent_events (tenant_id, id)');
        DB::statement('CREATE INDEX IF NOT EXISTS push_subscriptions_tenant_updated ON push_subscriptions (tenant_id, updated_at, id)');
        DB::statement('CREATE INDEX IF NOT EXISTS carts_tenant_customer_seen ON carts (tenant_id, customer_id, last_seen_at)');
    }

    public function down(): void
    {
        foreach (['customer_events_tenant_id_cursor', 'consent_events_tenant_id_cursor', 'push_subscriptions_tenant_updated', 'carts_tenant_customer_seen'] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS automation_version_immutable()');
    }
};
