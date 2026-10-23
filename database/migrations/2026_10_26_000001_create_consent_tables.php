<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical consent model.
 *
 * Consent belongs to a recipient (an e-mail address or phone number), not
 * to a customer: one address can belong to several customers (IGS has 45
 * e-mails and 6,356 phone numbers shared by different members), and İYS
 * itself only knows addresses. recipient_customers links the two without
 * merging customers.
 *
 * consents holds the latest observation per recipient, channel, recipient
 * type and source provider (İYS, Ticimax, newsletter, lead form, ...). The
 * eligibility engine combines them; no single row is "the" decision.
 * consent_events is the append-only history of every observed change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('address_type', 8);
            // Normalized: e-mail trimmed and lower-cased, phone E.164.
            $table->string('address', 320);
            $table->boolean('is_valid')->default(true);
            $table->string('invalid_reason', 32)->nullable();
            // Relay addresses of marketplaces never receive marketing.
            $table->boolean('is_marketplace')->default(false);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'address_type', 'address']);
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE consent_recipients ADD CONSTRAINT consent_recipients_type_check CHECK (address_type IN ('email', 'phone'))");

        Schema::create('recipient_customers', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('recipient_id');
            $table->uuid('customer_id');
            $table->string('link_source', 32);
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['tenant_id', 'recipient_id', 'customer_id']);
            $table->foreign(['tenant_id', 'recipient_id'])->references(['tenant_id', 'id'])->on('consent_recipients')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->cascadeOnDelete();
            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::create('consents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('recipient_id');
            $table->string('channel', 8);
            $table->string('recipient_type', 16)->default('INDIVIDUAL');
            $table->string('source_provider', 32);
            $table->string('status', 16);
            // Provider's own source label (e.g. İYS HS_WEB, Ticimax TicimaxWeb).
            $table->string('source', 64)->nullable();
            $table->timestamp('consent_date')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->string('external_id', 128)->nullable();
            $table->jsonb('evidence')->default('{}');
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->foreign(['tenant_id', 'recipient_id'])->references(['tenant_id', 'id'])->on('consent_recipients')->cascadeOnDelete();
            $table->unique(['tenant_id', 'recipient_id', 'channel', 'recipient_type', 'source_provider']);
            $table->index(['tenant_id', 'source_provider', 'channel', 'status']);
        });
        DB::statement("ALTER TABLE consents ADD CONSTRAINT consents_status_check CHECK (status IN ('GRANTED', 'REVOKED', 'PENDING', 'UNKNOWN'))");
        DB::statement("ALTER TABLE consents ADD CONSTRAINT consents_channel_check CHECK (channel IN ('EMAIL', 'SMS', 'CALL', 'PUSH'))");
        DB::statement("ALTER TABLE consents ADD CONSTRAINT consents_recipient_type_check CHECK (recipient_type IN ('INDIVIDUAL', 'MERCHANT'))");

        Schema::create('consent_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('recipient_id');
            $table->string('name', 32);
            $table->string('channel', 8);
            $table->string('recipient_type', 16);
            $table->string('source_provider', 32);
            $table->string('old_status', 16)->nullable();
            $table->string('new_status', 16);
            $table->string('source', 64)->nullable();
            $table->string('provider_transaction_id', 128)->nullable();
            // Idempotency key per provider: transaction id, history row id, or a
            // content hash for providers without ids.
            $table->string('event_key', 191);
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('observed_at')->useCurrent();
            $table->boolean('is_backfill')->default(false);
            $table->jsonb('metadata')->default('{}');

            $table->foreign(['tenant_id', 'recipient_id'])->references(['tenant_id', 'id'])->on('consent_recipients')->cascadeOnDelete();
            $table->unique(['tenant_id', 'source_provider', 'event_key']);
            $table->index(['tenant_id', 'recipient_id', 'occurred_at']);
        });

        Schema::create('consent_conflicts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('recipient_id');
            $table->string('channel', 8);
            $table->string('kind', 48);
            $table->string('status', 16)->default('open');
            $table->jsonb('details')->default('{}');
            $table->timestamp('detected_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();

            $table->foreign(['tenant_id', 'recipient_id'])->references(['tenant_id', 'id'])->on('consent_recipients')->cascadeOnDelete();
            $table->unique(['tenant_id', 'recipient_id', 'channel', 'kind']);
            $table->index(['tenant_id', 'status', 'kind']);
        });

        Schema::create('suppressions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('recipient_id');
            // NULL = every channel of the address.
            $table->string('channel', 8)->nullable();
            $table->string('source', 32);
            $table->string('reason', 255)->nullable();
            $table->string('created_by', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('lifted_at')->nullable();
            $table->string('lifted_by', 64)->nullable();
            $table->string('lift_reason', 255)->nullable();

            $table->foreign(['tenant_id', 'recipient_id'])->references(['tenant_id', 'id'])->on('consent_recipients')->cascadeOnDelete();
            $table->index(['tenant_id', 'recipient_id']);
        });
        DB::statement("ALTER TABLE suppressions ADD CONSTRAINT suppressions_source_check CHECK (source IN ('MANUAL', 'KLAVIYO_UNSUBSCRIBE', 'KLAVIYO_BOUNCE', 'KLAVIYO_SPAM', 'IYS_RET', 'INVALID_EMAIL', 'SYSTEM'))");
        DB::statement("CREATE UNIQUE INDEX suppressions_one_active ON suppressions (tenant_id, recipient_id, COALESCE(channel, '*'), source) WHERE lifted_at IS NULL");

        // Stored report snapshots for the panel (counts only, no addresses).
        Schema::create('consent_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('kind', 32);
            $table->jsonb('payload');
            $table->timestamp('generated_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'kind', 'generated_at']);
        });

        // Opaque provider cursors (İYS "after").
        Schema::table('integration_sync_cursors', function (Blueprint $table) {
            $table->text('provider_cursor')->nullable();
        });

        foreach (['consent_recipients', 'recipient_customers', 'consents', 'consent_events', 'consent_conflicts', 'suppressions', 'consent_reports'] as $table) {
            Rls::isolateTenantTable($table);
        }
        Rls::grantApp('consent_recipients', ['SELECT', 'INSERT', 'UPDATE']);
        Rls::grantApp('recipient_customers', ['SELECT', 'INSERT', 'DELETE']);
        Rls::grantApp('consents', ['SELECT', 'INSERT', 'UPDATE']);
        Rls::grantApp('consent_events', ['SELECT', 'INSERT']);
        Rls::grantApp('consent_conflicts', ['SELECT', 'INSERT', 'UPDATE']);
        // Suppressions are lifted (UPDATE), never deleted.
        Rls::grantApp('suppressions', ['SELECT', 'INSERT', 'UPDATE']);
        Rls::grantApp('consent_reports', ['SELECT', 'INSERT']);
    }

    public function down(): void
    {
        Schema::table('integration_sync_cursors', function (Blueprint $table) {
            $table->dropColumn('provider_cursor');
        });
        foreach (['consent_reports', 'suppressions', 'consent_conflicts', 'consent_events', 'consents', 'recipient_customers', 'consent_recipients'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
