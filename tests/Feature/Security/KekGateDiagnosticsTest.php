<?php

namespace Tests\Feature\Security;

use App\Console\Commands\VerifyKek;
use App\Security\Vault\KekMaintenance;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * The deploy gate fails (never passes) when it cannot run, and says why
 * without printing SQL, host, user, password or any secret. Wrong-KEK cases
 * are covered in CloudSecretKekTest.
 */
final class KekGateDiagnosticsTest extends TestCase
{
    use InteractsWithTenants;

    /**
     * Runs the gate with a broken runtime connection, then restores it.
     */
    private function gateWith(array $connection): array
    {
        $original = config('database.connections.pgsql');
        config(['database.connections.pgsql' => $connection + $original]);
        DB::purge('pgsql');
        try {
            $exit = Artisan::call('vault:verify-kek');

            return [$exit, Artisan::output(), $original];
        } finally {
            config(['database.connections.pgsql' => $original]);
            DB::purge('pgsql');
        }
    }

    private function assertNoSecrets(string $output, array $connection): void
    {
        foreach (array_filter([$connection['password'] ?? null, $connection['username'] ?? null, $connection['host'] ?? null, (string) ($connection['port'] ?? '')]) as $secret) {
            $this->assertStringNotContainsString((string) $secret, $output);
        }
        foreach (['SQL:', 'select', 'Host:', 'password=', 'pgsql:host'] as $fragment) {
            $this->assertStringNotContainsStringIgnoringCase($fragment, $output);
        }
    }

    public function test_valid_kek_and_database_with_data_keys_passes(): void
    {
        $this->createTenant('alpha');
        $this->createTenant('beta');

        $this->assertSame(0, Artisan::call('vault:verify-kek'));
        $output = Artisan::output();
        $this->assertStringContainsString('sample data key unwraps (2 data keys, 2 tenants)', $output);
        $this->assertStringContainsString('KEK verification passed.', $output);
    }

    public function test_unreachable_database_fails_the_gate_with_a_safe_diagnostic(): void
    {
        $this->createTenant('alpha');
        [$exit, $output, $original] = $this->gateWith(['port' => 1]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('stage=runtime_role_check exception=QueryException sqlstate=08006 category=connection_failure reason=connection_refused connection=pgsql', $output);
        $this->assertStringNotContainsString('passed', $output);
        $this->assertNoSecrets($output, ['host' => $original['host'], 'username' => $original['username'], 'password' => $original['password']]);
    }

    public function test_authentication_failure_fails_the_gate_and_never_prints_the_password(): void
    {
        [$exit, $output, $original] = $this->gateWith(['password' => 'deploy-time-wrong-secret-91x']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('reason=password_authentication_failed', $output);
        $this->assertNoSecrets($output, ['password' => 'deploy-time-wrong-secret-91x', 'username' => $original['username'], 'host' => $original['host']]);
    }

    public function test_query_errors_after_connecting_report_sqlstate_category_and_no_sql(): void
    {
        $pdo = new PDOException('SQLSTATE[42501]: Insufficient privilege: 7 ERROR: permission denied for function app_kek_samples');
        $pdo->errorInfo = ['42501', 7, 'ERROR: permission denied for function app_kek_samples'];
        $e = new QueryException('pgsql', 'SELECT * FROM app_kek_samples() WHERE secret = ?', ['bound-secret-value'], $pdo);

        $line = VerifyKek::diagnostic($e, 'data_key_samples');

        $this->assertSame('stage=data_key_samples exception=QueryException sqlstate=42501 category=insufficient_privilege reason=permission_denied object=function:app_kek_samples privilege=EXECUTE connection=pgsql', $line);
        $this->assertStringNotContainsString('bound-secret-value', $line);
        $this->assertStringNotContainsString('SELECT', $line);
        $this->assertSame('stage=current_kek_self_test exception=RuntimeException', VerifyKek::diagnostic(new \RuntimeException('KEK material abc'), 'current_kek_self_test'));
    }

    /**
     * Runs a statement as the owner (it owns the functions), then undoes it.
     */
    private function withOwnerChange(string $do, string $undo, \Closure $fn): void
    {
        DB::connection('pgsql_owner')->statement($do);
        try {
            $fn();
        } finally {
            DB::connection('pgsql_owner')->statement($undo);
        }
    }

    public function test_missing_inventory_execute_fails_42501_and_names_the_function_and_role(): void
    {
        $this->createTenant('alpha');
        $app = config('database.roles.app');
        $this->withOwnerChange("REVOKE EXECUTE ON FUNCTION app_kek_inventory() FROM {$app}", "GRANT EXECUTE ON FUNCTION app_kek_inventory() TO {$app}", function () {
            $this->assertSame(1, Artisan::call('vault:verify-kek'));
            $output = Artisan::output();
            $this->assertStringContainsString('stage=data_key_inventory exception=QueryException sqlstate=42501 category=insufficient_privilege reason=permission_denied object=function:app_kek_inventory privilege=EXECUTE connection=pgsql db_user=saas_app session_user=saas_app expected_role=saas_app', $output);
            $this->assertStringNotContainsString('passed', $output);
            $this->assertNoSecrets($output, ['password' => config('database.connections.pgsql.password'), 'host' => config('database.connections.pgsql.host')]);
        });
        $this->assertSame(0, Artisan::call('vault:verify-kek'), 'Grant restored: passes again.');
    }

    public function test_a_runtime_connection_that_is_not_the_rls_bound_app_role_fails_before_any_key_is_read(): void
    {
        $this->createTenant('alpha');
        // The owner role (owns every table) as the runtime connection.
        [$exit, $output] = $this->gateWith(['username' => config('database.connections.pgsql_owner.username'), 'password' => config('database.connections.pgsql_owner.password')]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('runtime connection is the RLS-bound app role (db_user=saas_owner, bypass_rls=false, superuser=false)', $output);
        $this->assertStringContainsString('runtime_role_not_rls_bound', $output);
        $this->assertStringNotContainsString('sample data key', $output, 'Stops before touching data keys.');

        $base = ['db_user' => 'saas_app', 'session_user' => 'saas_app', 'superuser' => false, 'bypass_rls' => false, 'privileged_member' => false];
        $this->assertTrue(KekMaintenance::rlsBound($base));
        foreach (['bypass_rls' => true, 'superuser' => true, 'privileged_member' => true, 'session_user' => 'neondb_owner', 'db_user' => 'saas_platform'] as $k => $v) {
            $this->assertFalse(KekMaintenance::rlsBound([$k => $v] + $base), "{$k} must fail the gate");
        }
    }

    public function test_inventory_helpers_return_metadata_only_and_are_closed_to_public(): void
    {
        $this->createTenant('alpha');
        $this->createTenant('beta');
        $row = (array) DB::selectOne('SELECT * FROM app_kek_inventory()');
        $this->assertSame(['kek_id', 'data_keys', 'active_keys', 'tenants'], array_keys($row));
        $this->assertSame(['kek_id', 'tenant_id', 'version'], array_keys((array) DB::selectOne('SELECT * FROM app_kek_samples()')));

        $owner = DB::connection('pgsql_owner');
        foreach (['app_kek_inventory()', 'app_kek_samples()', 'app_kek_stale_tenants(text)'] as $fn) {
            $p = $owner->selectOne("SELECT has_function_privilege('public', ?::regprocedure, 'EXECUTE') AS pub, p.prosecdef AS definer, pg_get_userbyid(p.proowner) AS owner,
                EXISTS (SELECT 1 FROM unnest(coalesce(p.proconfig, '{}')) c WHERE c LIKE 'search_path=%') AS fixed FROM pg_proc p WHERE p.oid = ?::regprocedure", [$fn, $fn]);
            $this->assertFalse($p->pub, "{$fn}: PUBLIC must not execute");
            $this->assertTrue($p->definer && $p->fixed, "{$fn}: definer with a fixed search_path");
            $this->assertSame(config('database.roles.owner'), $p->owner);
        }
        // The runtime role never reads wrapped keys across tenants: no context, no rows; RLS is forced.
        $this->assertSame(0, DB::table('tenant_data_keys')->count());
        $this->assertTrue((bool) $owner->selectOne("SELECT relforcerowsecurity AS f FROM pg_class WHERE oid = 'tenant_data_keys'::regclass")->f);
        $this->assertFalse((bool) $owner->selectOne("SELECT has_table_privilege(?, 'tenant_data_keys', 'DELETE') AS d", [config('database.roles.app')])->d);
    }

    public function test_db_verify_roles_catches_excessive_grants(): void
    {
        $this->assertSame(0, Artisan::call('db:verify-roles'));
        $this->withOwnerChange('GRANT EXECUTE ON FUNCTION app_kek_samples() TO PUBLIC', 'REVOKE EXECUTE ON FUNCTION app_kek_samples() FROM PUBLIC', function () {
            $this->assertSame(1, Artisan::call('db:verify-roles'));
            $this->assertStringContainsString('unsafe: app_kek_samples', Artisan::output());
        });
        $app = config('database.roles.app');
        $this->withOwnerChange("GRANT SELECT ON platform_tenant_notes TO {$app}", "REVOKE SELECT ON platform_tenant_notes FROM {$app}", function () {
            $this->assertSame(1, Artisan::call('db:verify-roles'));
            $this->assertStringContainsString('reachable or missing: platform_tenant_notes', Artisan::output());
        });
        $platform = config('database.roles.platform');
        $this->withOwnerChange("GRANT EXECUTE ON FUNCTION app_kek_samples() TO {$platform}", "REVOKE EXECUTE ON FUNCTION app_kek_samples() FROM {$platform}", function () {
            $this->assertSame(1, Artisan::call('db:verify-roles'));
            $this->assertStringContainsString('not allowed: app_kek_samples', Artisan::output());
        });
        $this->assertSame(0, Artisan::call('db:verify-roles'), 'Every grant restored.');
    }
}
