<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail. Enforced three ways: the runtime and platform
 * roles have no UPDATE/DELETE grant, a trigger rejects UPDATE and DELETE for
 * every role, and the Eloquent model refuses to update or delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('actor_type', 32);
            $table->string('actor_id', 64)->nullable();
            $table->string('action', 64);
            $table->string('resource_type', 64)->nullable();
            $table->string('resource_id', 64)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            // No FK to tenants: the trail must outlive a deleted tenant.
            $table->index(['tenant_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_reject_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only' USING ERRCODE = 'insufficient_privilege';
            END
            $$
        SQL);
        DB::statement('CREATE TRIGGER audit_logs_append_only BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION audit_logs_reject_mutation()');

        $app = Rls::appRole();
        $platform = Rls::platformRole();

        DB::statement('ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_logs FORCE ROW LEVEL SECURITY');
        // Tenants read their own trail; tenant-less (platform/system) rows may be
        // written by the app role but are never readable by it.
        DB::statement("CREATE POLICY audit_read ON audit_logs FOR SELECT TO {$app} USING (tenant_id = app_current_tenant())");
        DB::statement("CREATE POLICY audit_write ON audit_logs FOR INSERT TO {$app} WITH CHECK (tenant_id IS NULL OR tenant_id = app_current_tenant())");
        DB::statement("CREATE POLICY platform_write ON audit_logs FOR INSERT TO {$platform} WITH CHECK (true)");
        Rls::grantApp('audit_logs', ['SELECT', 'INSERT']);
        Rls::grantPlatformRead('audit_logs', ['id', 'tenant_id', 'actor_type', 'actor_id', 'action', 'resource_type', 'resource_id', 'metadata', 'request_id', 'created_at']);
        DB::statement("GRANT INSERT ON audit_logs TO {$platform}");
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS audit_logs_reject_mutation()');
    }
};
