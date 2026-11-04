<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform tenant management (Super Admin "Müşteriler"):
 *
 * - tenants: contact phone, plan (extension point only, no billing),
 *   suspension time and reason. Column grants for the platform role.
 * - platform_tenant_notes: Super Admin-only notes about a tenant. FORCE RLS
 *   with a policy for the platform role only; the runtime role has no grant
 *   at all, so no tenant query, API or model path can read it. Not for
 *   secrets.
 * - users.activated_at: null for an account created by an invitation until
 *   its owner sets a password (explicit null on insert). Existing accounts
 *   and every other creation path count as activated.
 * - tenant_users.status "invited": a membership that grants nothing until the
 *   invitation is accepted.
 * - user_invitations: tenant data under FORCE RLS. Only the SHA-256 of the
 *   single-use token is stored.
 * - SECURITY DEFINER lookups (fixed search_path, schema-qualified static
 *   SQL, PUBLIC revoked, one role each), returning the minimum:
 *   app_invitation_tenant(hash)      → tenant of a still-open invitation (app role)
 *   app_platform_tenant_members(id)  → members of one tenant with name/e-mail (platform role)
 *   app_platform_tenant_owners()     → owner name/e-mail per tenant (platform role)
 *   The platform role still has no grant on users or any personal table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('contact_phone', 32)->nullable();
            // Future plans (starter, growth, pro, enterprise); null = no plan. No billing.
            $table->string('plan', 32)->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 255)->nullable();
        });
        DB::statement('GRANT SELECT (contact_phone, plan, suspended_at, suspension_reason) ON tenants TO '.Rls::platformRole());

        Schema::create('platform_tenant_notes', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->primary()->constrained('tenants')->cascadeOnDelete();
            $table->string('note', 1000);
            $table->foreignUuid('updated_by')->constrained('platform_admins');
            $table->timestamp('updated_at')->useCurrent();
        });
        $platform = Rls::platformRole();
        DB::statement('ALTER TABLE platform_tenant_notes ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE platform_tenant_notes FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY platform_only ON platform_tenant_notes FOR ALL TO {$platform} USING (true) WITH CHECK (true)");
        DB::statement("GRANT SELECT, INSERT, UPDATE ON platform_tenant_notes TO {$platform}");
        DB::statement('REVOKE ALL ON platform_tenant_notes FROM PUBLIC, '.Rls::appRole());

        Schema::table('users', function (Blueprint $table) {
            // Every other creation path (operator-set password) is activated; invitations insert null.
            $table->timestamp('activated_at')->nullable()->useCurrent();
        });
        // created_at is nullable: never leave an existing account unable to sign in.
        DB::statement('UPDATE users SET activated_at = COALESCE(created_at, now())');

        DB::statement('ALTER TABLE tenant_users DROP CONSTRAINT tenant_users_status_check');
        DB::statement("ALTER TABLE tenant_users ADD CONSTRAINT tenant_users_status_check CHECK (status IN ('active', 'disabled', 'invited'))");

        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('membership_id');
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 16);
            // sha256 of the token; the token itself is never stored.
            $table->char('token_hash', 64)->unique();
            $table->string('delivery', 16)->default('manual');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('created_by', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'membership_id'])->references(['tenant_id', 'id'])->on('tenant_users')->cascadeOnDelete();
            $table->index(['tenant_id', 'membership_id']);
        });
        DB::statement("ALTER TABLE user_invitations ADD CONSTRAINT user_invitations_delivery_check CHECK (delivery IN ('manual', 'email'))");
        Rls::isolateTenantTable('user_invitations');
        Rls::grantApp('user_invitations', ['SELECT', 'INSERT', 'UPDATE']);
        Rls::grantPlatformRead('user_invitations', ['id', 'tenant_id', 'membership_id', 'role', 'delivery', 'expires_at', 'accepted_at', 'revoked_at', 'created_at']);

        // The definer functions run as the owner, which needs to see rows.
        $owner = Rls::ownerRole();
        DB::statement("CREATE POLICY owner_directory ON tenant_users FOR SELECT TO {$owner} USING (true)");
        DB::statement("CREATE POLICY owner_directory ON user_invitations FOR SELECT TO {$owner} USING (true)");

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_invitation_tenant(p_token_hash text) RETURNS uuid
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public
            AS $$ SELECT i.tenant_id FROM public.user_invitations i
                  WHERE i.token_hash = p_token_hash AND i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at > now() $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_platform_tenant_members(p_tenant uuid)
            RETURNS TABLE (membership_id uuid, user_id uuid, name text, email text, role text, status text, activated boolean, created_at timestamp)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public
            AS $$
                SELECT m.id, u.id, u.name::text, u.email::text, m.role::text, m.status::text, u.activated_at IS NOT NULL, m.created_at
                FROM public.tenant_users m JOIN public.users u ON u.id = m.user_id
                WHERE m.tenant_id = p_tenant
                ORDER BY m.created_at, u.email
            $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_platform_tenant_owners()
            RETURNS TABLE (tenant_id uuid, name text, email text, status text)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public
            AS $$
                SELECT DISTINCT ON (m.tenant_id) m.tenant_id, u.name::text, u.email::text, m.status::text
                FROM public.tenant_users m JOIN public.users u ON u.id = m.user_id
                WHERE m.role = 'owner' AND m.status <> 'disabled'
                ORDER BY m.tenant_id, (m.status = 'active') DESC, m.created_at
            $$
        SQL);

        $app = Rls::appRole();
        foreach (['app_invitation_tenant(text)' => $app, 'app_platform_tenant_members(uuid)' => $platform, 'app_platform_tenant_owners()' => $platform] as $function => $role) {
            DB::statement("REVOKE ALL ON FUNCTION {$function} FROM PUBLIC");
            DB::statement("GRANT EXECUTE ON FUNCTION {$function} TO {$role}");
        }
    }

    public function down(): void
    {
        foreach (['app_platform_tenant_owners()', 'app_platform_tenant_members(uuid)', 'app_invitation_tenant(text)'] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}");
        }
        DB::statement('DROP POLICY IF EXISTS owner_directory ON tenant_users');
        Schema::dropIfExists('user_invitations');
        DB::statement("UPDATE tenant_users SET status = 'disabled' WHERE status = 'invited'");
        DB::statement('ALTER TABLE tenant_users DROP CONSTRAINT tenant_users_status_check');
        DB::statement("ALTER TABLE tenant_users ADD CONSTRAINT tenant_users_status_check CHECK (status IN ('active', 'disabled'))");
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('activated_at'));
        Schema::dropIfExists('platform_tenant_notes');
        DB::statement('REVOKE SELECT (contact_phone, plan, suspended_at, suspension_reason) ON tenants FROM '.Rls::platformRole());
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn(['contact_phone', 'plan', 'suspended_at', 'suspension_reason']));
    }
};
