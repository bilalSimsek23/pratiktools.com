<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail delivery core (provider independent). Providers (Amazon SES
 * first) only deliver: campaign, consent, suppression, templates, events
 * and usage live here. Event payloads are normalized; recipient addresses
 * in events are stored only as hashes.
 */
return new class extends Migration
{
    private const TABLES = [
        'email_sender_identities', 'email_templates', 'email_template_versions', 'email_audience_snapshots', 'email_audience_members',
        'email_messages', 'email_events', 'email_unsubscribes', 'email_usage_daily', 'consent_write_intents',
    ];

    public function up(): void
    {
        // Who a tenant sends as. The domain choice is the tenant's; nothing
        // here creates or verifies identities at the provider.
        Schema::create('email_sender_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('integration_id');
            $table->string('provider', 32);
            $table->string('domain', 253);
            $table->string('from_email', 320);
            $table->string('from_name', 120)->nullable();
            $table->string('reply_to_email', 320)->nullable();
            // Identity name at the provider (domain or address).
            $table->string('provider_identity_id', 320);
            // verified | pending | failed | temporary_failure | not_started | unknown
            $table->string('verification_status', 24)->default('unknown');
            $table->string('dkim_status', 24)->default('unknown');
            $table->string('mail_from_domain', 253)->nullable();
            $table->string('mail_from_status', 24)->default('not_configured');
            $table->string('spf_status', 24)->default('unknown');
            $table->string('dmarc_status', 24)->default('unknown');
            // Normalized DNS records to publish (never applied by us).
            $table->jsonb('dns_records')->default('[]');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'integration_id'])->references(['tenant_id', 'id'])->on('tenant_integrations')->cascadeOnDelete();
            $table->unique(['tenant_id', 'integration_id', 'from_email']);
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('email_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 120);
            // marketing | transactional
            $table->string('message_type', 16);
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('current_version')->nullable();
            $table->string('created_by', 64)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE email_templates ADD CONSTRAINT email_templates_status_check CHECK (status IN ('draft', 'active', 'archived'))");

        // Versions are immutable once written; a change is a new version.
        Schema::create('email_template_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('template_id');
            $table->unsignedInteger('version');
            $table->string('subject_template', 400);
            $table->text('html_template')->nullable();
            $table->text('text_template');
            $table->jsonb('variables')->default('[]');
            $table->string('status', 16)->default('draft');
            $table->string('created_by', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'template_id'])->references(['tenant_id', 'id'])->on('email_templates')->cascadeOnDelete();
            $table->unique(['tenant_id', 'template_id', 'version']);
            $table->unique(['tenant_id', 'id']);
        });

        // Frozen audience: membership never changes after creation, so a
        // campaign sends to exactly who was selected.
        Schema::create('email_audience_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('campaign_id')->nullable();
            $table->jsonb('criteria')->default('{}');
            $table->unsignedInteger('member_count')->default(0);
            $table->jsonb('exclusions')->default('{}');
            $table->string('created_by', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('email_audience_members', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('snapshot_id');
            $table->uuid('recipient_id');
            $table->uuid('customer_id')->nullable();

            $table->primary(['tenant_id', 'snapshot_id', 'recipient_id']);
            $table->foreign(['tenant_id', 'snapshot_id'])->references(['tenant_id', 'id'])->on('email_audience_snapshots')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'recipient_id'])->references(['tenant_id', 'id'])->on('consent_recipients')->cascadeOnDelete();
        });

        // One row per message and recipient. The idempotency key stops a
        // duplicate request or queue delivery from sending twice; the row
        // is pinned to one integration (no silent provider fallback).
        Schema::create('email_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('integration_id');
            $table->uuid('sender_identity_id');
            $table->string('provider', 32);
            $table->uuid('campaign_id')->nullable();
            $table->uuid('automation_run_id')->nullable();
            $table->uuid('snapshot_id')->nullable();
            $table->uuid('template_version_id')->nullable();
            $table->uuid('customer_id')->nullable();
            $table->uuid('recipient_id')->nullable();
            $table->string('to_email', 320);
            $table->string('from_email', 320);
            $table->string('from_name', 120)->nullable();
            $table->string('reply_to', 320)->nullable();
            $table->string('subject', 400);
            $table->text('html_body')->nullable();
            $table->text('text_body');
            $table->jsonb('tags')->default('{}');
            $table->jsonb('metadata')->default('{}');
            $table->jsonb('headers')->default('{}');
            // marketing | transactional | test
            $table->string('message_type', 16);
            $table->string('idempotency_key', 128);
            // queued | sending | sent | blocked | deferred | failed | cancelled
            $table->string('status', 16)->default('queued');
            // Latest provider outcome: delivered | bounced | complained | rejected | delayed
            $table->string('delivery_status', 16)->nullable();
            $table->string('provider_message_id', 128)->nullable();
            $table->string('block_reason', 48)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedInteger('size_bytes')->default(0);
            $table->string('requested_by', 64)->nullable();
            $table->timestamp('send_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'integration_id'])->references(['tenant_id', 'id'])->on('tenant_integrations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'sender_identity_id'])->references(['tenant_id', 'id'])->on('email_sender_identities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->nullOnDelete();
            $table->foreign(['tenant_id', 'recipient_id'])->references(['tenant_id', 'id'])->on('consent_recipients')->nullOnDelete();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'next_attempt_at']);
            $table->index(['tenant_id', 'recipient_id', 'message_type', 'sent_at']);
            $table->index(['provider', 'provider_message_id']);
            $table->index(['tenant_id', 'campaign_id']);
        });
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_type_check CHECK (message_type IN ('marketing', 'transactional', 'test'))");
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_status_check CHECK (status IN ('queued', 'sending', 'sent', 'blocked', 'deferred', 'failed', 'cancelled'))");

        // Canonical provider events, one per provider event (event_key).
        Schema::create('email_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('message_id')->nullable();
            $table->string('provider', 32);
            $table->string('provider_message_id', 128)->nullable();
            $table->string('event_type', 32);
            $table->string('event_key', 64);
            $table->char('recipient_hash', 64)->nullable();
            // hard | soft | undetermined (bounces only)
            $table->string('bounce_class', 16)->nullable();
            $table->jsonb('details')->default('{}');
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->uuid('webhook_inbox_id')->nullable();

            $table->foreign(['tenant_id', 'message_id'])->references(['tenant_id', 'id'])->on('email_messages')->nullOnDelete();
            $table->unique(['tenant_id', 'provider', 'event_key']);
            $table->index(['tenant_id', 'event_type', 'occurred_at']);
            $table->index(['tenant_id', 'recipient_hash', 'event_type']);
        });

        // Replay-safe unsubscribe: one row per token; a second click finds it.
        Schema::create('email_unsubscribes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('recipient_id');
            $table->uuid('message_id')->nullable();
            $table->char('token_hash', 64);
            // link | one_click
            $table->string('method', 16);
            $table->uuid('suppression_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'recipient_id'])->references(['tenant_id', 'id'])->on('consent_recipients')->cascadeOnDelete();
            $table->unique(['tenant_id', 'token_hash']);
        });

        // Provider-neutral usage per day, for reports and later billing.
        Schema::create('email_usage_daily', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->string('provider', 32);
            $table->date('day');
            $table->unsignedInteger('emails_attempted')->default(0);
            $table->unsignedInteger('emails_sent')->default(0);
            $table->unsignedInteger('emails_delivered')->default(0);
            $table->unsignedInteger('emails_bounced')->default(0);
            $table->unsignedInteger('complaints')->default(0);
            $table->unsignedBigInteger('bytes_sent')->default(0);
            $table->unsignedInteger('provider_requests')->default(0);

            $table->primary(['tenant_id', 'provider', 'day']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        // Changes that must reach İYS once writing is enabled (RET after an
        // unsubscribe). Recorded only; nothing is sent in this phase.
        Schema::create('consent_write_intents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('recipient_id');
            $table->string('target', 16);
            $table->string('channel', 8);
            $table->string('status', 16);
            $table->string('source', 32);
            // pending_write_disabled | pending | written | failed
            $table->string('state', 24)->default('pending_write_disabled');
            $table->timestamps();

            $table->foreign(['tenant_id', 'recipient_id'])->references(['tenant_id', 'id'])->on('consent_recipients')->cascadeOnDelete();
            $table->index(['tenant_id', 'state']);
        });

        DB::statement('ALTER TABLE suppressions DROP CONSTRAINT suppressions_source_check');
        DB::statement("ALTER TABLE suppressions ADD CONSTRAINT suppressions_source_check CHECK (source IN ('MANUAL', 'KLAVIYO_UNSUBSCRIBE', 'KLAVIYO_BOUNCE', 'KLAVIYO_SPAM', 'IYS_RET', 'INVALID_EMAIL', 'SYSTEM', 'SES_BOUNCE', 'SES_COMPLAINT', 'UNSUBSCRIBE', 'SOFT_BOUNCE'))");

        foreach (self::TABLES as $table) {
            Rls::isolateTenantTable($table);
            Rls::grantApp($table, ['SELECT', 'INSERT', 'UPDATE']);
        }
    }

    public function down(): void
    {
        // The wider suppressions source check stays: rolling back must never
        // delete a bounce, complaint or unsubscribe suppression.
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
