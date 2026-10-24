<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Push channel (OneSignal first). Provider data lives in its own tables and
 * is never merged into customers: a push profile links to a customer only
 * through a trusted external id, otherwise it stays unresolved.
 *
 * Push tokens, e-mail and SMS identifiers from the provider are never
 * stored; email/SMS subscriptions are skipped entirely.
 *
 * Push permission is its own channel and never feeds the e-mail/SMS consent
 * model (consents / İYS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('integration_id');
            $table->string('provider', 32);
            $table->string('provider_user_id', 64);
            $table->string('external_id', 191)->nullable();
            $table->uuid('customer_id')->nullable();
            // resolved | unresolved | no_external_id | conflict
            $table->string('identity_status', 16)->default('no_external_id');
            $table->string('identity_source', 32)->nullable();
            $table->unsignedInteger('subscription_count')->default(0);
            $table->timestamp('first_active_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->string('content_hash', 32)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'integration_id'])->references(['tenant_id', 'id'])->on('tenant_integrations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->nullOnDelete();
            $table->unique(['tenant_id', 'provider', 'provider_user_id']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'provider', 'external_id']);
            $table->index(['tenant_id', 'customer_id']);
        });
        DB::statement("ALTER TABLE push_profiles ADD CONSTRAINT push_profiles_identity_status_check CHECK (identity_status IN ('resolved', 'unresolved', 'no_external_id', 'conflict'))");

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('integration_id');
            $table->uuid('push_profile_id')->nullable();
            $table->string('provider', 32);
            $table->string('provider_subscription_id', 64);
            $table->string('channel', 8)->default('push');
            $table->string('platform', 16);
            $table->smallInteger('device_type');
            $table->string('status', 20);
            $table->integer('status_code')->nullable();
            $table->string('device_model', 128)->nullable();
            $table->string('device_os', 64)->nullable();
            $table->string('app_version', 64)->nullable();
            $table->string('language', 8)->nullable();
            $table->char('country', 2)->nullable();
            $table->unsignedInteger('session_count')->default(0);
            $table->timestamp('first_session_at')->nullable();
            $table->timestamp('last_session_at')->nullable();
            $table->timestamp('last_unsubscribed_at')->nullable();
            // Present in the latest full export; null = gone at the provider.
            $table->uuid('last_seen_run_id')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->string('content_hash', 32)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'integration_id'])->references(['tenant_id', 'id'])->on('tenant_integrations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'push_profile_id'])->references(['tenant_id', 'id'])->on('push_profiles')->nullOnDelete();
            $table->unique(['tenant_id', 'provider', 'provider_subscription_id']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'platform']);
            $table->index(['tenant_id', 'push_profile_id']);
        });
        DB::statement("ALTER TABLE push_subscriptions ADD CONSTRAINT push_subscriptions_status_check CHECK (status IN ('subscribed', 'unsubscribed', 'never_subscribed'))");

        // External id → customer, for ids we issue and for legacy formats.
        Schema::create('push_identity_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('provider', 32);
            $table->string('external_id', 191);
            $table->uuid('customer_id');
            // platform | legacy | manual
            $table->string('source', 16);
            $table->string('created_by', 64)->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->cascadeOnDelete();
            $table->unique(['tenant_id', 'provider', 'external_id']);
            $table->index(['tenant_id', 'customer_id']);
        });

        // Internal push opt-outs / suppressions (lifted, never deleted).
        Schema::create('push_suppressions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('push_profile_id');
            $table->string('reason', 255);
            $table->string('created_by', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('lifted_at')->nullable();
            $table->string('lifted_by', 64)->nullable();

            $table->foreign(['tenant_id', 'push_profile_id'])->references(['tenant_id', 'id'])->on('push_profiles')->cascadeOnDelete();
            $table->index(['tenant_id', 'push_profile_id']);
        });

        // Only these recipients may receive anything in test_only mode.
        Schema::create('test_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('channel', 8);
            $table->string('provider', 32);
            $table->string('provider_recipient_id', 191);
            $table->string('label', 120);
            $table->string('approved_by', 64);
            $table->boolean('active')->default(true);
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'channel', 'provider', 'provider_recipient_id']);
        });

        // One row per send attempt; the idempotency key is reused on retries
        // and stops a duplicate queue delivery from sending twice.
        Schema::create('push_send_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('integration_id');
            $table->uuid('idempotency_key');
            $table->string('mode', 16);
            $table->string('status', 16)->default('requested');
            $table->unsignedInteger('recipient_count');
            $table->jsonb('recipients');
            $table->jsonb('message');
            $table->string('provider_message_id', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('requested_by', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'integration_id'])->references(['tenant_id', 'id'])->on('tenant_integrations')->cascadeOnDelete();
            $table->unique(['tenant_id', 'idempotency_key']);
        });
        DB::statement("ALTER TABLE push_send_requests ADD CONSTRAINT push_send_requests_status_check CHECK (status IN ('requested', 'sending', 'sent', 'not_sent', 'failed', 'blocked'))");

        foreach (['push_profiles', 'push_subscriptions', 'push_identity_mappings', 'push_suppressions', 'test_recipients', 'push_send_requests'] as $table) {
            Rls::isolateTenantTable($table);
        }
        Rls::grantApp('push_profiles', ['SELECT', 'INSERT', 'UPDATE']);
        Rls::grantApp('push_subscriptions', ['SELECT', 'INSERT', 'UPDATE']);
        Rls::grantApp('push_identity_mappings', ['SELECT', 'INSERT', 'UPDATE']);
        Rls::grantApp('push_suppressions', ['SELECT', 'INSERT', 'UPDATE']);
        Rls::grantApp('test_recipients', ['SELECT', 'INSERT', 'UPDATE']);
        Rls::grantApp('push_send_requests', ['SELECT', 'INSERT', 'UPDATE']);
    }

    public function down(): void
    {
        foreach (['push_send_requests', 'test_recipients', 'push_suppressions', 'push_identity_mappings', 'push_subscriptions', 'push_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
