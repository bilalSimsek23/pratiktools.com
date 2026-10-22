<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-independent webhook inbox. Every incoming webhook is stored
 * before it is processed on the "webhooks" queue. external_event_id makes
 * delivery idempotent per provider and tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_inbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('integration_id')->nullable();
            $table->string('provider', 32);
            $table->string('event_type', 128)->nullable();
            $table->string('external_event_id', 191)->nullable();
            $table->jsonb('payload');
            $table->jsonb('headers_minimized')->default('{}');
            $table->string('signature_status', 16);
            $table->string('processing_status', 16)->default('pending');
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Composite, tenant-aware FK: an inbox row can only point at an
            // integration of the same tenant.
            $table->foreign(['tenant_id', 'integration_id'])->references(['tenant_id', 'id'])->on('tenant_integrations')->cascadeOnDelete();
            $table->index(['tenant_id', 'processing_status', 'received_at']);
        });

        DB::statement("ALTER TABLE webhook_inbox ADD CONSTRAINT webhook_inbox_signature_status_check CHECK (signature_status IN ('valid', 'invalid', 'missing', 'not_configured'))");
        DB::statement("ALTER TABLE webhook_inbox ADD CONSTRAINT webhook_inbox_processing_status_check CHECK (processing_status IN ('pending', 'processing', 'processed', 'failed', 'ignored'))");
        DB::statement("CREATE UNIQUE INDEX webhook_inbox_idempotency ON webhook_inbox (provider, COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000'::uuid), external_event_id) WHERE external_event_id IS NOT NULL");

        $app = Rls::appRole();

        // Tenant rows in tenant context; tenant-less rows only in the explicit
        // "system" context used by the platform webhook processor.
        DB::statement('ALTER TABLE webhook_inbox ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE webhook_inbox FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY inbox_isolation ON webhook_inbox FOR ALL TO {$app}
            USING (tenant_id = app_current_tenant() OR (tenant_id IS NULL AND app_current_context() = 'system'))
            WITH CHECK (tenant_id = app_current_tenant() OR (tenant_id IS NULL AND app_current_context() = 'system'))");
        Rls::grantApp('webhook_inbox', ['SELECT', 'INSERT', 'UPDATE']);
        Rls::grantPlatformRead('webhook_inbox', ['id', 'tenant_id', 'provider', 'event_type', 'signature_status', 'processing_status', 'received_at', 'processed_at', 'attempts']);
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_inbox');
    }
};
