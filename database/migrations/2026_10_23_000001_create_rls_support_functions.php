<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Session-level tenant context helpers used by every row-level security
 * policy. The application sets app.tenant_id, app.user_id and app.context
 * with set_config(); an unset or empty value reads as NULL, which makes the
 * tenant policies match nothing (fail-closed).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_current_tenant() RETURNS uuid
            LANGUAGE sql STABLE
            AS $$ SELECT NULLIF(current_setting('app.tenant_id', true), '')::uuid $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_current_user() RETURNS uuid
            LANGUAGE sql STABLE
            AS $$ SELECT NULLIF(current_setting('app.user_id', true), '')::uuid $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_current_context() RETURNS text
            LANGUAGE sql STABLE
            AS $$ SELECT COALESCE(NULLIF(current_setting('app.context', true), ''), 'none') $$
        SQL);

        foreach (['app_current_tenant()', 'app_current_user()', 'app_current_context()'] as $function) {
            DB::statement("REVOKE ALL ON FUNCTION {$function} FROM PUBLIC");
            DB::statement("GRANT EXECUTE ON FUNCTION {$function} TO ".Rls::appRole().', '.Rls::platformRole());
        }
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS app_current_context()');
        DB::statement('DROP FUNCTION IF EXISTS app_current_user()');
        DB::statement('DROP FUNCTION IF EXISTS app_current_tenant()');
    }
};
