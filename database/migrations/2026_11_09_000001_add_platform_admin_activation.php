<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Super Admin activation links (platform:admin:invite):
 *
 * - platform_admins.status gains "invited": an account that cannot sign in
 *   until its owner sets a password through the link.
 * - activation_token_hash / activation_expires_at: SHA-256 of the
 *   single-use token and its expiry; only an invited account has them.
 * - app_platform_admin_activate(hash, password_hash): SECURITY DEFINER,
 *   platform role only. Changes only an invited, unexpired row with that
 *   token, stores an already hashed password, activates it and clears the
 *   token. The platform role still has no UPDATE on password or status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->char('activation_token_hash', 64)->nullable()->unique();
            $table->timestamp('activation_expires_at')->nullable();
        });
        DB::statement("ALTER TABLE platform_admins ADD CONSTRAINT platform_admins_status_check CHECK (status IN ('active', 'invited', 'disabled'))");
        DB::statement("ALTER TABLE platform_admins ADD CONSTRAINT platform_admins_activation_check CHECK (activation_token_hash IS NULL OR (status = 'invited' AND activation_expires_at IS NOT NULL))");

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_platform_admin_activate(p_token_hash text, p_password_hash text) RETURNS uuid
            LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path = pg_catalog, public
            AS $$
                UPDATE public.platform_admins
                SET password = p_password_hash, status = 'active', activation_token_hash = NULL, activation_expires_at = NULL, updated_at = now()
                WHERE activation_token_hash = p_token_hash
                  AND status = 'invited'
                  AND activation_expires_at > now()
                  AND p_password_hash ~ '^\$(2y|argon2i|argon2id)\$'
                RETURNING id
            $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION app_platform_admin_activate(text, text) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION app_platform_admin_activate(text, text) TO '.Rls::platformRole());
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS app_platform_admin_activate(text, text)');
        // An invited account never had a usable password: it stays unable to sign in.
        DB::statement("UPDATE platform_admins SET status = 'disabled', activation_token_hash = NULL, activation_expires_at = NULL WHERE status = 'invited'");
        DB::statement('ALTER TABLE platform_admins DROP CONSTRAINT IF EXISTS platform_admins_activation_check');
        DB::statement('ALTER TABLE platform_admins DROP CONSTRAINT IF EXISTS platform_admins_status_check');
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->dropUnique(['activation_token_hash']);
            $table->dropColumn(['activation_token_hash', 'activation_expires_at']);
        });
    }
};
