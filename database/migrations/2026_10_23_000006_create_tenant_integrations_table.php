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
        Schema::create('tenant_integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('capability', 32);
            $table->string('status', 24)->default('pending');
            // AES-256-GCM envelope (JSON). Never plaintext, never returned by the API.
            $table->text('encrypted_secret')->nullable();
            $table->unsignedInteger('secret_key_version')->nullable();
            $table->string('secret_fingerprint', 64)->nullable();
            $table->jsonb('scopes')->default('[]');
            $table->jsonb('settings')->default('{}');
            $table->timestamp('last_verified_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'capability']);
            $table->unique(['tenant_id', 'id']);
        });

        DB::statement("ALTER TABLE tenant_integrations ADD CONSTRAINT tenant_integrations_status_check CHECK (status IN ('pending', 'connected', 'error', 'action_required', 'syncing', 'disabled'))");

        Rls::isolateTenantTable('tenant_integrations');
        Rls::grantApp('tenant_integrations');
        // No encrypted_secret, fingerprint, settings or last_error for the platform role.
        Rls::grantPlatformRead('tenant_integrations', ['id', 'tenant_id', 'provider', 'capability', 'status', 'last_verified_at', 'created_at', 'updated_at']);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_integrations');
    }
};
