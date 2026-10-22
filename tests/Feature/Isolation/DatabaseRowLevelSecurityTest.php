<?php

namespace Tests\Feature\Isolation;

use App\Models\Customer;
use App\Models\TenantIntegration;
use App\Tenancy\Exceptions\TenantContextMissing;
use App\Tenancy\Exceptions\TenantMismatch;
use App\Tenancy\TenantScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Proves isolation at the PostgreSQL level: even raw SQL or Eloquent with
 * the tenant scope removed cannot read or write another tenant's rows.
 */
#[Group('tenant-isolation')]
final class DatabaseRowLevelSecurityTest extends TestCase
{
    use InteractsWithTenants;

    public function test_raw_sql_only_returns_rows_of_the_current_tenant(): void
    {
        [$a] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');
        $this->createCustomer($a, 'a1@alpha.test');
        $this->createCustomer($b, 'b1@beta.test');

        $emails = $this->inTenant($a, fn () => collect(DB::select('SELECT email, tenant_id FROM customers')));

        $this->assertSame(['a1@alpha.test'], $emails->pluck('email')->all());
        $this->assertSame([$a->id], $emails->pluck('tenant_id')->unique()->values()->all());
    }

    public function test_eloquent_without_global_scope_still_cannot_see_other_tenants(): void
    {
        [$a] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');
        $theirs = $this->createCustomer($b, 'b1@beta.test');

        $found = $this->inTenant($a, fn () => Customer::query()->withoutGlobalScope(TenantScope::class)->whereKey($theirs->id)->first());
        $all = $this->inTenant($a, fn () => Customer::query()->withoutGlobalScopes()->count());

        $this->assertNull($found);
        $this->assertSame(0, $all);
    }

    public function test_without_any_context_the_database_returns_nothing(): void
    {
        [$a] = $this->createTenant('alpha');
        $this->createCustomer($a, 'a1@alpha.test');
        $this->createIntegration($a);

        foreach (['customers', 'tenant_integrations', 'tenant_settings', 'tenant_data_keys', 'tenant_users', 'tenants', 'webhook_inbox', 'audit_logs'] as $table) {
            $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM {$table}")->n, "{$table} must be empty without context");
        }
    }

    public function test_eloquent_without_context_fails_closed(): void
    {
        $this->expectException(TenantContextMissing::class);

        Customer::query()->get();
    }

    public function test_cannot_insert_a_row_for_another_tenant(): void
    {
        [$a] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');

        // Database level: WITH CHECK rejects the foreign tenant_id.
        $this->inTenant($a, function () use ($b) {
            try {
                DB::insert('INSERT INTO customers (id, tenant_id, email, created_at, updated_at) VALUES (?, ?, ?, now(), now())', [(string) Str::uuid7(), $b->id, 'x@beta.test']);
                $this->fail('Insert for another tenant must be rejected by RLS.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('row-level security', $e->getMessage());
            }
        });

        // Application level: the model refuses as well.
        $this->expectException(TenantMismatch::class);
        $this->inTenant($a, function () use ($b) {
            $customer = new Customer(['email' => 'x@beta.test']);
            $customer->tenant_id = $b->id;
            $customer->save();
        });
    }

    public function test_cannot_update_or_delete_another_tenants_rows_with_raw_sql(): void
    {
        [$a] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');
        $theirs = $this->createCustomer($b, 'b1@beta.test');

        $updated = $this->inTenant($a, fn () => DB::update("UPDATE customers SET email = 'pwned@alpha.test' WHERE id = ?", [$theirs->id]));
        $deleted = $this->inTenant($a, fn () => DB::delete('DELETE FROM customers WHERE id = ?', [$theirs->id]));
        $moved = $this->inTenant($a, fn () => DB::update('UPDATE customers SET tenant_id = ? WHERE id = ?', [$a->id, $theirs->id]));

        $this->assertSame(0, $updated);
        $this->assertSame(0, $deleted);
        $this->assertSame(0, $moved);
        $this->assertSame('b1@beta.test', $this->inTenant($b, fn () => Customer::query()->find($theirs->id)?->email));
    }

    public function test_a_row_cannot_be_moved_into_another_tenant(): void
    {
        [$a] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');
        $mine = $this->createCustomer($a, 'a1@alpha.test');

        $this->expectException(QueryException::class);
        $this->inTenant($a, fn () => DB::update('UPDATE customers SET tenant_id = ? WHERE id = ?', [$b->id, $mine->id]));
    }

    public function test_composite_foreign_key_prevents_cross_tenant_references(): void
    {
        [$a] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');
        $foreignIntegration = $this->createIntegration($b);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('foreign key');

        $this->inTenant($a, fn () => DB::insert(
            "INSERT INTO webhook_inbox (id, tenant_id, integration_id, provider, payload, signature_status) VALUES (?, ?, ?, 'klaviyo', '{}'::jsonb, 'not_configured')",
            [(string) Str::uuid7(), $a->id, $foreignIntegration->id],
        ));
    }

    public function test_platform_role_cannot_read_personal_data_or_secrets(): void
    {
        [$a] = $this->createTenant('alpha');
        $this->createCustomer($a, 'a1@alpha.test');
        $this->createIntegration($a);
        $platform = DB::connection('pgsql_platform');

        $this->assertSame(1, $platform->table('tenants')->count());
        $this->assertSame(1, $platform->table('tenant_integrations')->select('id', 'status')->count());

        foreach ([
            'SELECT email FROM customers',
            'SELECT email FROM users',
            'SELECT encrypted_secret FROM tenant_integrations',
            'SELECT secret_fingerprint FROM tenant_integrations',
            'SELECT wrapped_key FROM tenant_data_keys',
            'SELECT payload FROM webhook_inbox',
        ] as $sql) {
            try {
                $platform->select($sql);
                $this->fail("Platform role must not run: {$sql}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('permission denied', $e->getMessage());
            }
        }
    }

    public function test_runtime_role_cannot_bypass_rls_or_change_schema(): void
    {
        $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertFalse((bool) $role->rolsuper);
        $this->assertFalse((bool) $role->rolbypassrls);

        $forced = collect(DB::connection('pgsql_owner')->select(
            "SELECT relname FROM pg_class WHERE relkind = 'r' AND relnamespace = 'public'::regnamespace AND relrowsecurity AND relforcerowsecurity"
        ))->pluck('relname');

        foreach (['tenants', 'tenant_users', 'tenant_settings', 'tenant_data_keys', 'tenant_integrations', 'customers', 'audit_logs', 'webhook_inbox'] as $table) {
            $this->assertContains($table, $forced, "{$table} must have FORCE ROW LEVEL SECURITY");
        }

        $this->expectException(QueryException::class);
        DB::statement('ALTER TABLE customers DISABLE ROW LEVEL SECURITY');
    }

    public function test_every_table_with_tenant_id_is_protected_by_rls(): void
    {
        $unprotected = collect(DB::connection('pgsql_owner')->select(<<<'SQL'
            SELECT c.relname
            FROM pg_class c
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped
            WHERE c.relkind = 'r' AND c.relnamespace = 'public'::regnamespace
              AND NOT (c.relrowsecurity AND c.relforcerowsecurity)
        SQL))->pluck('relname')->all();

        // Tables whose tenant_id is a pointer, not tenant data, are listed explicitly.
        $allowed = ['personal_access_tokens', 'break_glass_grants'];

        $this->assertSame([], array_values(array_diff($unprotected, $allowed)));
    }

    public function test_tenant_integrations_secret_is_hidden_from_model_serialization(): void
    {
        [$a] = $this->createTenant('alpha');
        $integration = $this->createIntegration($a, 'very-secret-provider-key-123');

        $json = $this->inTenant($a, fn () => TenantIntegration::query()->find($integration->id)->toJson());

        $this->assertStringNotContainsString('encrypted_secret', $json);
        $this->assertStringNotContainsString('very-secret-provider-key-123', $json);
    }
}
