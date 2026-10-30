<?php

namespace Tests\Feature\Security;

use App\Console\Commands\VerifyKek;
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
        $this->assertStringContainsString('stage=data_key_table_check exception=QueryException sqlstate=08006 category=connection_failure reason=connection_refused connection=pgsql', $output);
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

        $this->assertSame('stage=data_key_samples exception=QueryException sqlstate=42501 category=insufficient_privilege reason=permission_denied connection=pgsql', $line);
        $this->assertStringNotContainsString('bound-secret-value', $line);
        $this->assertStringNotContainsString('SELECT', $line);
        $this->assertSame('stage=current_kek_self_test exception=RuntimeException', VerifyKek::diagnostic(new \RuntimeException('KEK material abc'), 'current_kek_self_test'));
    }
}
