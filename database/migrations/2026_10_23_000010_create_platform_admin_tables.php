<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Super Admin identities. Completely separate from tenant users: the runtime
 * app role has no grant here, and admins are created by a console command
 * that uses the owner connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->string('status', 16)->default('active');
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('break_glass_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('platform_admin_id')->constrained('platform_admins');
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('reason', 500);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'expires_at']);
        });

        $platform = Rls::platformRole();

        DB::statement("GRANT SELECT ON platform_admins TO {$platform}");
        DB::statement("GRANT UPDATE (two_factor_secret, two_factor_confirmed_at, remember_token, last_login_at, updated_at) ON platform_admins TO {$platform}");
        DB::statement("GRANT SELECT, INSERT ON break_glass_grants TO {$platform}");
        DB::statement("GRANT UPDATE (revoked_at) ON break_glass_grants TO {$platform}");
    }

    public function down(): void
    {
        Schema::dropIfExists('break_glass_grants');
        Schema::dropIfExists('platform_admins');
    }
};
