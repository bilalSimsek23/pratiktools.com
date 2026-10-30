<?php

namespace App\Console\Commands;

use App\Security\Vault\KekMaintenance;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Deploy gate, run before migrations: the configured KEKs open every data
 * key in use. Exit code 1 stops the deploy, so a wrong or missing KEK never
 * goes live. Prints KEK ids (derived fingerprints) and results only.
 *
 * When the check cannot run, it fails (never passes) and prints a safe
 * diagnostic: the stage reached, the exception class and, for database
 * errors, the SQLSTATE, its category and the connection name. Never the
 * exception message, SQL, bindings, host, DSN, user or any secret.
 */
final class VerifyKek extends Command
{
    protected $signature = 'vault:verify-kek';

    protected $description = 'Verify that the configured KEKs open every data key in use (deploy gate)';

    /**
     * SQLSTATE class => category (PostgreSQL error codes, appendix A).
     */
    private const SQLSTATE_CLASSES = [
        '08' => 'connection', '28' => 'authentication', '3D' => 'database_missing', '3F' => 'schema_missing',
        '42' => 'syntax_or_access', '53' => 'insufficient_resources', '57' => 'server_shutdown_or_canceled',
        '58' => 'system_error', '25' => 'transaction_state', '40' => 'transaction_rollback', 'XX' => 'internal_error',
    ];

    private const SQLSTATE_DETAIL = ['42501' => 'insufficient_privilege', '42P01' => 'undefined_table', '42883' => 'undefined_function', '3D000' => 'database_missing',
        '28P01' => 'invalid_password', '28000' => 'invalid_authorization', '08006' => 'connection_failure', '08001' => 'connection_refused', '57P03' => 'cannot_connect_now'];

    private const CONNECT_REASONS = [
        'password authentication failed' => 'password_authentication_failed', 'no pg_hba.conf entry' => 'host_not_allowed',
        'does not exist' => 'database_or_role_missing', 'Connection refused' => 'connection_refused', 'timeout expired' => 'timeout',
        'timed out' => 'timeout', 'could not translate host name' => 'host_not_resolved', 'SSL' => 'ssl', 'too many connections' => 'too_many_connections',
        'remaining connection slots' => 'too_many_connections', 'the database system is starting up' => 'database_starting',
        'the database system is shutting down' => 'database_shutting_down', 'terminating connection' => 'connection_terminated',
        'permission denied' => 'permission_denied', 'server closed the connection' => 'connection_closed', 'endpoint' => 'endpoint_unavailable',
    ];

    public function handle(): int
    {
        $maintenance = app(KekMaintenance::class);
        try {
            $result = $maintenance->verify(app()->isProduction());
        } catch (Throwable $e) {
            $this->error('KEK verification could not run: '.self::diagnostic($e, $maintenance->stage).'. Check the database connection and SECRET_VAULT_* settings.');

            return self::FAILURE;
        }

        $this->table(['check', 'kek_id', 'result'], array_map(fn ($r) => [$r['check'], $r['kek_id'] ?? '-', $r['result']], $result['rows']));

        if (! $result['ok']) {
            $this->error('KEK verification FAILED: do not deploy. See docs/production/secret-vault-rotation.md.');

            return self::FAILURE;
        }

        $this->info('KEK verification passed.');

        return self::SUCCESS;
    }

    /**
     * key=value pairs that never include message text, SQL or credentials.
     */
    public static function diagnostic(Throwable $e, string $stage): string
    {
        $parts = ['stage='.$stage, 'exception='.class_basename($e)];
        $pdo = $e instanceof QueryException || $e instanceof PDOException ? $e : ($e->getPrevious() instanceof PDOException ? $e->getPrevious() : null);
        if ($pdo !== null) {
            $state = is_array($pdo->errorInfo ?? null) && is_string($pdo->errorInfo[0] ?? null) ? $pdo->errorInfo[0] : (string) $pdo->getCode();
            $state = preg_match('/^[0-9A-Z]{5}$/', $state) === 1 ? $state : (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $pdo->getMessage(), $m) === 1 ? $m[1] : 'unknown');
            $parts[] = 'sqlstate='.$state;
            $parts[] = 'category='.(self::SQLSTATE_DETAIL[$state] ?? self::SQLSTATE_CLASSES[substr($state, 0, 2)] ?? 'database');
            // Connect-time errors all share 08006: name the cause from fixed
            // server phrases (only this token is printed, never the text).
            foreach (self::CONNECT_REASONS as $phrase => $reason) {
                if (stripos($pdo->getMessage(), $phrase) !== false) {
                    $parts[] = 'reason='.$reason;
                    break;
                }
            }
            if ($e instanceof QueryException) {
                $parts[] = 'connection='.preg_replace('/[^a-z0-9_]/i', '', (string) $e->getConnectionName());
            }
        }

        return implode(' ', $parts);
    }
}
