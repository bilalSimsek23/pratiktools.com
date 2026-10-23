<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One cursor per integration and entity. Advanced only after a whole
        // window has been read and stored successfully.
        Schema::create('integration_sync_cursors', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('integration_id');
            $table->string('entity', 32);
            $table->timestamp('last_window_end')->nullable();
            $table->timestamp('last_full_sync_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();

            $table->primary(['tenant_id', 'integration_id', 'entity']);
            $table->foreign(['tenant_id', 'integration_id'])->references(['tenant_id', 'id'])->on('tenant_integrations')->cascadeOnDelete();
        });

        Schema::create('integration_sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('integration_id');
            $table->string('entity', 32);
            $table->string('mode', 8);
            $table->string('status', 16)->default('running');
            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();
            $table->string('page_token', 255)->nullable();
            $table->unsignedInteger('records_read')->default(0);
            $table->unsignedInteger('records_inserted')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('records_skipped')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->unsignedInteger('api_calls')->default(0);
            $table->unsignedInteger('slices')->default(0);
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->string('error_category', 32)->nullable();
            $table->string('error_message', 500)->nullable();

            $table->foreign(['tenant_id', 'integration_id'])->references(['tenant_id', 'id'])->on('tenant_integrations')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'integration_id', 'entity', 'started_at']);
        });
        DB::statement("ALTER TABLE integration_sync_runs ADD CONSTRAINT integration_sync_runs_status_check CHECK (status IN ('running', 'succeeded', 'failed'))");
        DB::statement("ALTER TABLE integration_sync_runs ADD CONSTRAINT integration_sync_runs_mode_check CHECK (mode IN ('full', 'delta'))");
        // At most one running sync per integration and entity.
        DB::statement("CREATE UNIQUE INDEX integration_sync_runs_one_running ON integration_sync_runs (tenant_id, integration_id, entity) WHERE status = 'running'");

        Schema::create('integration_errors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('integration_id');
            $table->uuid('sync_run_id')->nullable();
            $table->string('category', 32);
            $table->string('operation', 64)->nullable();
            $table->string('message', 500);
            $table->jsonb('context')->default('{}');
            $table->timestamp('occurred_at')->useCurrent();

            $table->foreign(['tenant_id', 'integration_id'])->references(['tenant_id', 'id'])->on('tenant_integrations')->cascadeOnDelete();
            $table->index(['tenant_id', 'integration_id', 'occurred_at']);
        });
        DB::statement("ALTER TABLE integration_errors ADD CONSTRAINT integration_errors_category_check CHECK (category IN ('auth', 'rate_limited', 'retryable', 'permanent', 'schema_mismatch'))");

        foreach (['integration_sync_cursors', 'integration_sync_runs', 'integration_errors'] as $table) {
            Rls::isolateTenantTable($table);
        }
        Rls::grantApp('integration_sync_cursors', ['SELECT', 'INSERT', 'UPDATE']);
        Rls::grantApp('integration_sync_runs', ['SELECT', 'INSERT', 'UPDATE']);
        Rls::grantApp('integration_errors', ['SELECT', 'INSERT']);

        Rls::grantPlatformRead('integration_sync_runs', ['id', 'tenant_id', 'integration_id', 'entity', 'mode', 'status', 'records_read', 'records_inserted', 'records_updated', 'errors', 'api_calls', 'started_at', 'finished_at', 'duration_ms', 'error_category']);
        Rls::grantPlatformRead('integration_errors', ['id', 'tenant_id', 'integration_id', 'category', 'operation', 'occurred_at']);
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_errors');
        Schema::dropIfExists('integration_sync_runs');
        Schema::dropIfExists('integration_sync_cursors');
    }
};
