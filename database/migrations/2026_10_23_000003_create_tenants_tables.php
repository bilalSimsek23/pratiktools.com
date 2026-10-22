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
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Opaque identifier for public URLs (webhooks). Never the UUID.
            $table->string('public_id', 32)->unique();
            $table->string('name');
            $table->string('slug', 64)->unique();
            $table->string('status', 16)->default('active');
            $table->char('country', 2);
            $table->string('timezone', 64);
            $table->char('currency', 3);
            $table->string('locale', 16);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('provisioning', 'active', 'suspended'))");

        Schema::create('tenant_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 16);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->unique(['tenant_id', 'id']);
            $table->index('user_id');
        });

        DB::statement("ALTER TABLE tenant_users ADD CONSTRAINT tenant_users_role_check CHECK (role IN ('owner', 'admin', 'marketer', 'viewer'))");
        DB::statement("ALTER TABLE tenant_users ADD CONSTRAINT tenant_users_status_check CHECK (status IN ('active', 'disabled'))");

        $app = Rls::appRole();

        // tenants: a user sees the current tenant and the tenants they are an
        // active member of (needed to choose a tenant at login). Writes only
        // inside the tenant's own context. No DELETE grant at all.
        DB::statement('ALTER TABLE tenants ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE tenants FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_read ON tenants FOR SELECT TO {$app} USING (
            id = app_current_tenant()
            OR EXISTS (
                SELECT 1 FROM tenant_users tu
                WHERE tu.tenant_id = tenants.id AND tu.user_id = app_current_user() AND tu.status = 'active'
            )
        )");
        DB::statement("CREATE POLICY tenant_insert ON tenants FOR INSERT TO {$app} WITH CHECK (id = app_current_tenant())");
        DB::statement("CREATE POLICY tenant_update ON tenants FOR UPDATE TO {$app} USING (id = app_current_tenant()) WITH CHECK (id = app_current_tenant())");
        // The SECURITY DEFINER lookup functions below run as the owner.
        DB::statement('CREATE POLICY owner_directory ON tenants FOR SELECT TO '.Rls::ownerRole().' USING (true)');
        Rls::grantApp('tenants', ['SELECT', 'INSERT', 'UPDATE']);
        Rls::grantPlatformRead('tenants', ['id', 'public_id', 'name', 'slug', 'status', 'country', 'timezone', 'currency', 'locale', 'created_at', 'updated_at']);

        // tenant_users: members of the current tenant, plus the signed-in
        // user's own memberships in other tenants (read only).
        DB::statement('ALTER TABLE tenant_users ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE tenant_users FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY membership_read ON tenant_users FOR SELECT TO {$app} USING (
            tenant_id = app_current_tenant() OR user_id = app_current_user()
        )");
        DB::statement("CREATE POLICY membership_write ON tenant_users FOR INSERT TO {$app} WITH CHECK (tenant_id = app_current_tenant())");
        DB::statement("CREATE POLICY membership_update ON tenant_users FOR UPDATE TO {$app} USING (tenant_id = app_current_tenant()) WITH CHECK (tenant_id = app_current_tenant())");
        DB::statement("CREATE POLICY membership_delete ON tenant_users FOR DELETE TO {$app} USING (tenant_id = app_current_tenant())");
        Rls::grantApp('tenant_users');
        Rls::grantPlatformRead('tenant_users', ['id', 'tenant_id', 'role', 'status', 'created_at']);

        // Directory lookups that must work before a tenant context exists.
        // They return ids only, never tenant data.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_tenant_id_by_slug(p_slug text) RETURNS uuid
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
            AS $$ SELECT id FROM tenants WHERE slug = p_slug AND status = 'active' $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_tenant_id_by_public_id(p_public_id text) RETURNS uuid
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
            AS $$ SELECT id FROM tenants WHERE public_id = p_public_id AND status = 'active' $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_active_tenant_ids() RETURNS SETOF uuid
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
            AS $$ SELECT id FROM tenants WHERE status = 'active' ORDER BY id $$
        SQL);

        foreach (['app_tenant_id_by_slug(text)', 'app_tenant_id_by_public_id(text)', 'app_active_tenant_ids()'] as $function) {
            DB::statement("REVOKE ALL ON FUNCTION {$function} FROM PUBLIC");
            DB::statement("GRANT EXECUTE ON FUNCTION {$function} TO {$app}");
        }
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS app_active_tenant_ids()');
        DB::statement('DROP FUNCTION IF EXISTS app_tenant_id_by_public_id(text)');
        DB::statement('DROP FUNCTION IF EXISTS app_tenant_id_by_slug(text)');
        // The tenants read policy references tenant_users.
        DB::statement('DROP POLICY IF EXISTS tenant_read ON tenants');
        Schema::dropIfExists('tenant_users');
        Schema::dropIfExists('tenants');
    }
};
