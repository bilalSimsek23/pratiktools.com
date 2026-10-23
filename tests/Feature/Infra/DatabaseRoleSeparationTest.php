<?php

namespace Tests\Feature\Infra;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

#[Group('tenant-isolation')]
final class DatabaseRoleSeparationTest extends TestCase
{
    use InteractsWithTenants;

    public function test_runtime_owner_and_platform_connections_use_separate_roles(): void
    {
        $roles = [];
        foreach (['pgsql', 'pgsql_owner', 'pgsql_platform'] as $connection) {
            $roles[$connection] = DB::connection($connection)->selectOne('SELECT current_user AS u')->u;
        }

        $this->assertSame(['pgsql' => 'saas_app', 'pgsql_owner' => 'saas_owner', 'pgsql_platform' => 'saas_platform'], $roles);
    }

    public function test_runtime_role_cannot_bypass_row_level_security(): void
    {
        [$a] = $this->createTenant('alpha');
        $this->createCustomer($a, 'a@alpha.test');

        $role = DB::selectOne('SELECT rolbypassrls, rolsuper FROM pg_roles WHERE rolname = current_user');
        $this->assertFalse((bool) $role->rolbypassrls);
        $this->assertFalse((bool) $role->rolsuper);
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM customers')->n, 'No tenant context, no rows.');

        try {
            DB::statement('ALTER TABLE customers DISABLE ROW LEVEL SECURITY');
            $this->fail('The runtime role must not be able to switch RLS off.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('must be owner', $e->getMessage());
        }

        try {
            DB::statement('CREATE TABLE sneaky (id int)');
            $this->fail('The runtime role must not create objects.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('permission denied', $e->getMessage());
        }
    }

    public function test_admin_role_cannot_read_tenant_personal_data(): void
    {
        [$a] = $this->createTenant('alpha');
        $this->createCustomer($a, 'a@alpha.test');
        $platform = DB::connection('pgsql_platform');

        foreach (['SELECT email FROM customers', 'SELECT * FROM customer_identities', 'SELECT payload FROM webhook_inbox', 'SELECT wrapped_key FROM tenant_data_keys', 'SELECT address FROM consent_recipients'] as $sql) {
            try {
                $platform->select($sql);
                $this->fail("Platform role must not run: {$sql}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('permission denied', $e->getMessage());
            }
        }

        // Metadata it needs for the panel stays readable.
        $this->assertIsArray($platform->select('SELECT id, provider, processing_status FROM webhook_inbox'));
    }

    public function test_role_verification_command_passes_on_a_correctly_bootstrapped_database(): void
    {
        $this->assertSame(0, Artisan::call('db:verify-roles'));
        $this->assertStringContainsString('Database role verification passed', Artisan::output());
        $this->assertStringNotContainsString((string) config('database.connections.pgsql.password'), Artisan::output());
    }

    public function test_role_verification_fails_when_runtime_and_admin_roles_collapse(): void
    {
        config(['database.roles.app' => 'saas_owner']);

        $this->assertSame(1, Artisan::call('db:verify-roles'));
        $this->assertStringContainsString('FAIL', Artisan::output());
    }

    public function test_http_and_queue_code_never_use_the_owner_connection(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php') && str_contains((string) file_get_contents($file->getPathname()), 'pgsql_owner')) {
                $offenders[] = str_replace(app_path().'/', '', $file->getPathname());
            }
        }

        sort($offenders);
        // Operator commands only: the read-only verifier, the Super Admin
        // account bootstrap (the platform role cannot insert admins), and the
        // guard, which only reads configuration. No HTTP or queue code.
        $this->assertSame(['Console/Commands/CreatePlatformAdmin.php', 'Console/Commands/VerifyDatabaseRoles.php', 'Support/ProductionGuard.php'], $offenders);
    }
}
