<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F2-R: consent without the İYS API.
 *
 * - tenant_settings.consent_strategy: direct_api (default, unchanged) or
 *   via_reconciliation; only an operator changes it.
 * - lead_forms.consent_mode: local_only (default) or via.
 * - via_consent_requests: one ViA consent acquisition per accepted lead
 *   (pending until a verified ViA success event; never ONAY by itself).
 * - consent_write_intents gain the route (direct_api | via_ret), a
 *   correlation reference and confirmation time.
 * - iys_reconciliation_batches / iys_consent_changes: operator-uploaded
 *   İYS change reports, analysed (dry-run) then applied once. Rows keep a
 *   keyed hash of the address and the recipient id, never the address.
 * - consent_events.reconciliation_batch_id: which batch caused a change.
 */
return new class extends Migration
{
    private const TABLES = ['via_consent_requests', 'iys_reconciliation_batches', 'iys_consent_changes'];

    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->string('consent_strategy', 24)->default('direct_api');
        });
        DB::statement("ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_consent_strategy_check CHECK (consent_strategy IN ('direct_api', 'via_reconciliation'))");

        Schema::table('lead_forms', function (Blueprint $table) {
            $table->string('consent_mode', 16)->default('local_only');
        });
        DB::statement("ALTER TABLE lead_forms ADD CONSTRAINT lead_forms_consent_mode_check CHECK (consent_mode IN ('local_only', 'via'))");

        Schema::table('consent_write_intents', function (Blueprint $table) {
            $table->string('route', 16)->default('direct_api');
            $table->string('reference', 64)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('last_error', 64)->nullable();
            $table->unique(['tenant_id', 'reference']);
            $table->index(['tenant_id', 'recipient_id', 'status', 'state']);
        });
        DB::statement("ALTER TABLE consent_write_intents ADD CONSTRAINT consent_write_intents_route_check CHECK (route IN ('direct_api', 'via_ret'))");

        Schema::create('via_consent_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('recipient_id');
            $table->uuid('customer_id')->nullable();
            $table->uuid('form_id')->nullable();
            $table->uuid('submission_id')->nullable();
            // Our correlation reference for the ViA process (random, opaque).
            $table->string('reference', 64);
            $table->string('channel', 8)->default('EMAIL');
            $table->string('purpose', 24)->default('newsletter');
            // pending | succeeded | failed | expired
            $table->string('state', 16)->default('pending');
            $table->string('provider_transaction_id', 128)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();

            $table->foreign(['tenant_id', 'recipient_id'])->references(['tenant_id', 'id'])->on('consent_recipients')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'form_id'])->references(['tenant_id', 'id'])->on('lead_forms')->nullOnDelete();
            $table->unique(['tenant_id', 'reference']);
            $table->unique(['tenant_id', 'provider_transaction_id']);
            $table->index(['tenant_id', 'recipient_id', 'state']);
        });
        DB::statement("ALTER TABLE via_consent_requests ADD CONSTRAINT via_consent_requests_state_check CHECK (state IN ('pending', 'succeeded', 'failed', 'expired'))");

        Schema::create('iys_reconciliation_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            // operator_upload now; sftp / secure_download / official_api later.
            $table->string('source', 24)->default('operator_upload');
            $table->string('format', 48)->nullable();
            $table->string('original_filename', 160);
            $table->char('file_sha256', 64);
            $table->unsignedBigInteger('file_size');
            $table->date('period_start')->nullable();
            $table->date('period_end');
            // analyzing | analyzed | unsupported | failed | applied
            $table->string('status', 16);
            $table->string('error', 64)->nullable();
            $table->jsonb('dry_run_counts')->default('{}');
            $table->jsonb('applied_counts')->default('{}');
            $table->string('created_by', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('analyzed_at')->nullable();
            $table->string('applied_by', 64)->nullable();
            $table->timestamp('applied_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // The same file is imported at most once per tenant.
            $table->unique(['tenant_id', 'file_sha256']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'period_end']);
        });
        DB::statement("ALTER TABLE iys_reconciliation_batches ADD CONSTRAINT iys_reconciliation_batches_status_check CHECK (status IN ('analyzing', 'analyzed', 'unsupported', 'failed', 'applied'))");

        Schema::create('iys_consent_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('batch_id');
            $table->unsignedInteger('row_number');
            // HMAC of the normalized address (AUDIT_HASH_KEY); no address.
            $table->char('address_hash', 64)->nullable();
            $table->uuid('recipient_id')->nullable();
            $table->string('channel', 8)->nullable();
            $table->string('recipient_type', 16)->nullable();
            $table->string('status', 16)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->string('source', 64)->nullable();
            // Identity of the change across reports (duplicate detection).
            $table->char('row_key', 64)->nullable();
            // onay | ret | unchanged | conflict | invalid | unknown_recipient | marketplace_excluded | duplicate
            $table->string('outcome', 24);
            $table->string('detail', 64)->nullable();
            $table->boolean('applied')->default(false);

            $table->foreign(['tenant_id', 'batch_id'])->references(['tenant_id', 'id'])->on('iys_reconciliation_batches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'recipient_id'])->references(['tenant_id', 'id'])->on('consent_recipients')->nullOnDelete();
            $table->unique(['tenant_id', 'batch_id', 'row_number']);
            $table->index(['tenant_id', 'batch_id', 'outcome']);
            $table->index(['tenant_id', 'row_key']);
        });

        Schema::table('consent_events', function (Blueprint $table) {
            $table->uuid('reconciliation_batch_id')->nullable();
        });

        foreach (self::TABLES as $table) {
            Rls::isolateTenantTable($table);
        }
        Rls::grantApp('via_consent_requests', ['SELECT', 'INSERT', 'UPDATE']);
        Rls::grantApp('iys_reconciliation_batches', ['SELECT', 'INSERT', 'UPDATE']);
        // Analysis rows are replaced when a batch is analysed again.
        Rls::grantApp('iys_consent_changes', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('consent_events', fn (Blueprint $table) => $table->dropColumn('reconciliation_batch_id'));
        Schema::table('consent_write_intents', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'reference']);
            $table->dropIndex(['tenant_id', 'recipient_id', 'status', 'state']);
            $table->dropColumn(['route', 'reference', 'confirmed_at', 'last_error']);
        });
        Schema::table('lead_forms', fn (Blueprint $table) => $table->dropColumn('consent_mode'));
        Schema::table('tenant_settings', fn (Blueprint $table) => $table->dropColumn('consent_strategy'));
    }
};
