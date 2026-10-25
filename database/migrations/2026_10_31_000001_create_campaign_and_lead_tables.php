<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F6: campaigns, published (immutable) template versions, lead forms and
 * submissions. Campaigns reuse F4 templates and sender identities and F5
 * segments/snapshots; nothing here dispatches a message.
 */
return new class extends Migration
{
    public function up(): void
    {
        // F4 templates: draft → published → archived; published versions are immutable.
        Schema::table('email_template_versions', function (Blueprint $table) {
            $table->string('preheader', 200)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamp('published_at')->nullable();
            $table->string('published_by', 64)->nullable();
        });
        DB::statement("UPDATE email_template_versions SET status = 'published', published_at = created_at WHERE status = 'active'");
        DB::statement('ALTER TABLE email_template_versions DROP CONSTRAINT IF EXISTS email_template_versions_status_check');
        DB::statement("ALTER TABLE email_template_versions ADD CONSTRAINT email_template_versions_status_check CHECK (status IN ('draft', 'published', 'archived'))");
        DB::statement("UPDATE email_templates SET status = 'published' WHERE status = 'active'");
        DB::statement('ALTER TABLE email_templates DROP CONSTRAINT email_templates_status_check');
        DB::statement("ALTER TABLE email_templates ADD CONSTRAINT email_templates_status_check CHECK (status IN ('draft', 'published', 'archived'))");
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION email_template_version_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.published_at IS NOT NULL AND (NEW.subject_template IS DISTINCT FROM OLD.subject_template OR NEW.html_template IS DISTINCT FROM OLD.html_template
                    OR NEW.text_template IS DISTINCT FROM OLD.text_template OR NEW.preheader IS DISTINCT FROM OLD.preheader OR NEW.variables IS DISTINCT FROM OLD.variables
                    OR NEW.metadata IS DISTINCT FROM OLD.metadata OR NEW.version IS DISTINCT FROM OLD.version OR NEW.published_at IS DISTINCT FROM OLD.published_at
                    OR (NEW.status = 'draft')) THEN
                    RAISE EXCEPTION 'email template version % is published and immutable', OLD.id USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS email_template_versions_immutable ON email_template_versions');
        DB::statement('CREATE TRIGGER email_template_versions_immutable BEFORE UPDATE ON email_template_versions FOR EACH ROW EXECUTE FUNCTION email_template_version_immutable()');

        // F5 snapshots: an optional purpose key makes a snapshot per purpose
        // (e.g. campaign:<id>) unique, so a retried freeze never duplicates.
        Schema::table('segment_snapshots', function (Blueprint $table) {
            $table->string('purpose_key', 80)->nullable();
            $table->unique(['tenant_id', 'purpose_key']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 160);
            // newsletter | promotional | announcement (all marketing)
            $table->string('type', 16);
            $table->string('channel', 8)->default('email');
            $table->string('message_type', 16)->default('marketing');
            // draft | ready | scheduled | paused | completed | cancelled | archived
            $table->string('status', 16)->default('draft');
            // segment | snapshot
            $table->string('audience_source', 16)->default('segment');
            $table->uuid('segment_id')->nullable();
            $table->uuid('segment_snapshot_id')->nullable();
            $table->uuid('template_version_id')->nullable();
            $table->uuid('integration_id')->nullable();
            $table->uuid('sender_identity_id')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->string('created_by', 64)->nullable();
            $table->string('published_by', 64)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'segment_id'])->references(['tenant_id', 'id'])->on('segments')->nullOnDelete();
            $table->foreign(['tenant_id', 'segment_snapshot_id'])->references(['tenant_id', 'id'])->on('segment_snapshots')->restrictOnDelete();
            $table->foreign(['tenant_id', 'template_version_id'])->references(['tenant_id', 'id'])->on('email_template_versions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'sender_identity_id'])->references(['tenant_id', 'id'])->on('email_sender_identities')->restrictOnDelete();
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_check CHECK (type IN ('newsletter', 'promotional', 'announcement') AND channel IN ('email')
            AND message_type = 'marketing' AND audience_source IN ('segment', 'snapshot')
            AND status IN ('draft', 'ready', 'scheduled', 'paused', 'completed', 'cancelled', 'archived'))");
        // Once frozen, audience, content and sender never change.
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
        DB::statement('CREATE TRIGGER campaigns_frozen_immutable BEFORE UPDATE ON campaigns FOR EACH ROW EXECUTE FUNCTION campaign_frozen_immutable()');

        Schema::create('lead_forms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            // Public, unguessable, form-scoped; carries no tenant credential.
            $table->string('public_id', 32)->unique();
            $table->string('name', 120);
            // popup | footer | landing_page | checkout
            $table->string('placement', 16);
            $table->boolean('active')->default(false);
            $table->text('consent_text');
            $table->unsignedInteger('consent_version')->default(1);
            $table->char('consent_text_hash', 64);
            $table->string('success_message', 300);
            $table->string('locale', 8)->default('tr');
            $table->jsonb('tags')->default('[]');
            // Browser origins allowed to post (exact scheme://host[:port]).
            $table->jsonb('allowed_origins')->default('[]');
            $table->boolean('double_opt_in')->default(false);
            $table->string('created_by', 64)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE lead_forms ADD CONSTRAINT lead_forms_placement_check CHECK (placement IN ('popup', 'footer', 'landing_page', 'checkout'))");

        Schema::create('lead_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('form_id');
            $table->char('idempotency_key', 64);
            // Keyed hash; the address itself lives only in consent_recipients.
            $table->char('email_hash', 64);
            $table->uuid('recipient_id')->nullable();
            $table->uuid('customer_id')->nullable();
            // accepted | marketplace_relay | identity_conflict
            $table->string('outcome', 24);
            $table->boolean('new_recipient')->default(false);
            $table->boolean('consent_checked');
            $table->unsignedInteger('consent_version');
            $table->char('consent_text_hash', 64);
            $table->string('placement', 16);
            $table->string('page_host', 253)->nullable();
            $table->string('page_path', 300)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->string('locale', 8)->nullable();
            // Store-side double opt-in (not İYS): none | pending | confirmed | expired
            $table->string('opt_in_status', 16)->default('none');
            $table->char('opt_in_token_hash', 64)->nullable();
            $table->timestamp('opt_in_expires_at')->nullable();
            $table->timestamp('opt_in_confirmed_at')->nullable();
            $table->timestamp('received_at')->useCurrent();

            $table->foreign(['tenant_id', 'form_id'])->references(['tenant_id', 'id'])->on('lead_forms')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'recipient_id'])->references(['tenant_id', 'id'])->on('consent_recipients')->nullOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->nullOnDelete();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'form_id', 'received_at']);
            $table->index(['tenant_id', 'email_hash']);
        });
        DB::statement("ALTER TABLE lead_submissions ADD CONSTRAINT lead_submissions_check CHECK (outcome IN ('accepted', 'marketplace_relay', 'identity_conflict')
            AND opt_in_status IN ('none', 'pending', 'confirmed', 'expired'))");

        // Counters for requests that are not stored (invalid, bot, rate limited).
        Schema::create('lead_metrics_daily', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('form_id');
            $table->date('day');
            foreach (['submissions', 'accepted', 'duplicate', 'invalid', 'bot_suspected', 'rate_limited', 'origin_refused', 'consent_checked', 'consent_unchecked', 'new_recipients'] as $c) {
                $table->unsignedInteger($c)->default(0);
            }

            $table->primary(['tenant_id', 'form_id', 'day']);
            $table->foreign(['tenant_id', 'form_id'])->references(['tenant_id', 'id'])->on('lead_forms')->cascadeOnDelete();
        });

        foreach (['campaigns', 'lead_forms', 'lead_submissions', 'lead_metrics_daily'] as $table) {
            Rls::isolateTenantTable($table);
            Rls::grantApp($table, ['SELECT', 'INSERT', 'UPDATE']);
        }
        Rls::grantPlatformRead('campaigns', ['id', 'tenant_id', 'type', 'status', 'created_at']);
        Rls::grantPlatformRead('lead_metrics_daily', ['tenant_id', 'form_id', 'day', 'submissions', 'accepted', 'duplicate', 'invalid', 'bot_suspected', 'rate_limited']);

        // Public form → tenant, without exposing anything else (only active forms of active tenants).
        // The function runs as the owner; lead_forms forces RLS even for its
        // owner, so the owner gets a read policy used only by this function.
        $app = Rls::appRole();
        DB::statement('CREATE POLICY owner_lead_form_lookup ON lead_forms FOR SELECT TO '.Rls::ownerRole().' USING (true)');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_lead_form_tenant(p_public_id text) RETURNS uuid
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
            AS $$ SELECT f.tenant_id FROM lead_forms f JOIN tenants t ON t.id = f.tenant_id
                  WHERE f.public_id = p_public_id AND f.active AND t.status = 'active' $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION app_lead_form_tenant(text) FROM PUBLIC');
        DB::statement("GRANT EXECUTE ON FUNCTION app_lead_form_tenant(text) TO {$app}");
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS app_lead_form_tenant(text)');
        DB::statement('DROP POLICY IF EXISTS owner_lead_form_lookup ON lead_forms');
        foreach (['lead_metrics_daily', 'lead_submissions', 'lead_forms', 'campaigns'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS campaign_frozen_immutable()');
        Schema::table('segment_snapshots', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'purpose_key']);
            $table->dropColumn('purpose_key');
        });
        DB::statement('DROP TRIGGER IF EXISTS email_template_versions_immutable ON email_template_versions');
        DB::statement('DROP FUNCTION IF EXISTS email_template_version_immutable()');
        DB::statement('ALTER TABLE email_template_versions DROP CONSTRAINT IF EXISTS email_template_versions_status_check');
        DB::statement('ALTER TABLE email_templates DROP CONSTRAINT email_templates_status_check');
        DB::statement("UPDATE email_templates SET status = 'active' WHERE status = 'published'");
        DB::statement("ALTER TABLE email_templates ADD CONSTRAINT email_templates_status_check CHECK (status IN ('draft', 'active', 'archived'))");
        DB::statement("UPDATE email_template_versions SET status = 'active' WHERE status = 'published'");
        Schema::table('email_template_versions', function (Blueprint $table) {
            $table->dropColumn(['preheader', 'metadata', 'published_at', 'published_by']);
        });
    }
};
