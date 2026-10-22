<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-level settings that later phases build on. timezone, locale and
 * currency live on tenants (single source of truth); this table holds
 * branding, domains and the sending_mode safety switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->primary()->constrained('tenants')->cascadeOnDelete();
            // off -> internal (allowlist only) -> pilot -> live. Defaults to off.
            $table->string('sending_mode', 16)->default('off');
            $table->jsonb('branding')->default('{}');
            $table->string('primary_domain')->nullable();
            $table->string('tracking_domain')->nullable();
            $table->string('wheel_domain')->nullable();
            $table->string('email_sender_domain')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_sending_mode_check CHECK (sending_mode IN ('off', 'internal', 'pilot', 'live'))");

        Rls::isolateTenantTable('tenant_settings');
        Rls::grantApp('tenant_settings', ['SELECT', 'INSERT', 'UPDATE']);
        Rls::grantPlatformRead('tenant_settings', ['tenant_id', 'sending_mode', 'created_at', 'updated_at']);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
