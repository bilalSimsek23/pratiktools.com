<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant data encryption keys (DEKs), stored only in wrapped form.
 * The key encryption key (KEK) that wraps them lives in the KMS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_data_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('wrapped_key');
            $table->string('kek_id', 128);
            $table->string('status', 16)->default('active');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('retired_at')->nullable();

            $table->unique(['tenant_id', 'version']);
            $table->unique(['tenant_id', 'id']);
        });

        DB::statement("ALTER TABLE tenant_data_keys ADD CONSTRAINT tenant_data_keys_status_check CHECK (status IN ('active', 'retired'))");
        // At most one active key per tenant.
        DB::statement("CREATE UNIQUE INDEX tenant_data_keys_one_active ON tenant_data_keys (tenant_id) WHERE status = 'active'");

        Rls::isolateTenantTable('tenant_data_keys');
        Rls::grantApp('tenant_data_keys', ['SELECT', 'INSERT', 'UPDATE']);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_data_keys');
    }
};
