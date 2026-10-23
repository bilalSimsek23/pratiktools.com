<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cross-tenant, read-only views of which KEK wraps which data keys, for
 * vault:verify-kek, vault:rewrap --all and /health. They return KEK ids
 * (derived fingerprints, not secrets), counts, and for the runtime role
 * only, one sample (tenant id, version) per KEK id. Never a wrapped key.
 *
 * The functions run as the owner. tenant_data_keys forces RLS even for its
 * owner, so the owner gets a SELECT-only policy on it; the owner role is
 * used for migrations only and owns the table anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        $owner = Rls::ownerRole();
        $app = Rls::appRole();
        $platform = Rls::platformRole();

        DB::statement("CREATE POLICY owner_kek_inventory ON tenant_data_keys FOR SELECT TO {$owner} USING (true)");

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_kek_inventory()
            RETURNS TABLE (kek_id text, data_keys bigint, active_keys bigint, tenants bigint)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT k.kek_id::text, count(*), count(*) FILTER (WHERE k.status = 'active'), count(DISTINCT k.tenant_id)
                FROM tenant_data_keys k GROUP BY k.kek_id ORDER BY k.kek_id
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_kek_samples()
            RETURNS TABLE (kek_id text, tenant_id uuid, version integer)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT DISTINCT ON (k.kek_id) k.kek_id::text, k.tenant_id, k.version::integer
                FROM tenant_data_keys k ORDER BY k.kek_id, k.created_at DESC
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_kek_stale_tenants(current_kek text)
            RETURNS TABLE (tenant_id uuid, stale_keys bigint)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT k.tenant_id, count(*) FROM tenant_data_keys k
                WHERE k.kek_id <> current_kek GROUP BY k.tenant_id ORDER BY k.tenant_id
            $$
            SQL);

        foreach (['app_kek_inventory()', 'app_kek_samples()', 'app_kek_stale_tenants(text)'] as $fn) {
            DB::statement("REVOKE ALL ON FUNCTION {$fn} FROM PUBLIC");
            DB::statement("GRANT EXECUTE ON FUNCTION {$fn} TO {$app}");
        }
        // Super Admin health: counts only.
        DB::statement("GRANT EXECUTE ON FUNCTION app_kek_inventory() TO {$platform}");
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS app_kek_stale_tenants(text)');
        DB::statement('DROP FUNCTION IF EXISTS app_kek_samples()');
        DB::statement('DROP FUNCTION IF EXISTS app_kek_inventory()');
        DB::statement('DROP POLICY IF EXISTS owner_kek_inventory ON tenant_data_keys');
    }
};
