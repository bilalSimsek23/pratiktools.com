<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only verification of the database role model, for after the role
 * bootstrap and after every migration:
 * - each connection logs in as its own role, none can bypass RLS;
 * - the owner owns every table; the runtime roles cannot create objects;
 * - every tenant table forces row-level security, and the runtime role
 *   sees no tenant rows without a tenant context;
 * - the platform (Super Admin) role cannot read tenant personal data.
 *
 * Prints only role names, counts and pass/fail. Exit code 1 on any failure.
 */
final class VerifyDatabaseRoles extends Command
{
    /** Tables with personal data or secrets the platform role must never read. */
    public const PII_TABLES = [
        'customers', 'customer_identities', 'customer_events', 'customer_merges', 'orders', 'order_items', 'carts', 'cart_items',
        'consent_recipients', 'consents', 'consent_events', 'consent_conflicts', 'suppressions', 'recipient_customers',
        'tenant_data_keys', 'users', 'personal_access_tokens',
    ];

    /** Tables the platform role may read metadata of, but never these columns. */
    public const PII_COLUMNS = [
        'webhook_inbox' => ['payload', 'headers_minimized'],
        'tenant_integrations' => ['encrypted_secret', 'settings'],
        // audit metadata is readable by design and must stay free of PII (redaction tests).
        'audit_logs' => ['ip_hash', 'user_agent_hash'],
    ];

    /**
     * Tables with a tenant_id that deliberately have no RLS: they are read
     * before a tenant context exists (token lookup) or are platform data.
     */
    public const NON_RLS_TENANT_TABLES = [
        'personal_access_tokens' => 'Sanctum resolves the token before the tenant context exists',
        'break_glass_grants' => 'platform table, written by Super Admin only',
    ];

    protected $signature = 'db:verify-roles';

    protected $description = 'Verify database roles, ownership, RLS and Super Admin isolation (read-only)';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    public function handle(): int
    {
        $roles = config('database.roles');

        foreach (['pgsql' => $roles['app'], 'pgsql_owner' => $roles['owner'], 'pgsql_platform' => $roles['platform']] as $connection => $expected) {
            $this->check("{$connection} logs in as its role", function () use ($connection, $expected) {
                $r = DB::connection($connection)->selectOne('SELECT current_user AS role, r.rolsuper AS super, r.rolbypassrls AS bypass FROM pg_roles r WHERE r.rolname = current_user');

                return [$r->role === $expected && ! $r->super && ! $r->bypass, "{$r->role}, superuser=".json_encode($r->super).', bypass_rls='.json_encode($r->bypass)];
            });
        }

        $this->check('distinct roles for runtime, owner and platform', fn () => [count(array_unique([$roles['app'], $roles['owner'], $roles['platform']])) === 3, implode(', ', [$roles['app'], $roles['owner'], $roles['platform']])]);

        $this->check('owner owns every table', function () use ($roles) {
            $n = (int) DB::connection('pgsql_owner')->selectOne("SELECT count(*) AS n FROM pg_tables WHERE schemaname = 'public' AND tableowner <> ?", [$roles['owner']])->n;

            return [$n === 0, "{$n} tables owned by another role"];
        });

        $this->check('runtime roles cannot create objects', function () use ($roles) {
            $r = DB::connection('pgsql_owner')->selectOne("SELECT has_schema_privilege(?, 'public', 'CREATE') AS app, has_schema_privilege(?, 'public', 'CREATE') AS platform", [$roles['app'], $roles['platform']]);

            return [! $r->app && ! $r->platform, 'app_create='.json_encode($r->app).', platform_create='.json_encode($r->platform)];
        });

        $this->check('every tenant table forces RLS', function () {
            $missing = DB::connection('pgsql_owner')->select(
                "SELECT c.relname FROM pg_class c JOIN pg_namespace ns ON ns.oid = c.relnamespace
                 JOIN information_schema.columns col ON col.table_schema = 'public' AND col.table_name = c.relname AND col.column_name = 'tenant_id'
                 WHERE ns.nspname = 'public' AND c.relkind = 'r' AND c.relname <> 'tenants' AND NOT (c.relrowsecurity AND c.relforcerowsecurity)",
            );
            $missing = array_values(array_filter($missing, fn ($t) => ! isset(self::NON_RLS_TENANT_TABLES[$t->relname])));

            return [$missing === [], $missing === [] ? 'all tenant tables' : count($missing).' without forced RLS: '.implode(', ', array_column($missing, 'relname'))];
        });

        $this->check('runtime role sees no tenant rows without context', function () {
            $n = (int) DB::connection('pgsql')->selectOne("SELECT current_setting('app.tenant_id', true) AS ctx, (SELECT count(*) FROM customers) AS n")->n;

            return [$n === 0, "{$n} visible rows"];
        });

        $this->check('platform role cannot read tenant personal data', function () use ($roles) {
            $readable = [];
            foreach (self::PII_TABLES as $table) {
                $r = DB::connection('pgsql_owner')->selectOne(
                    "SELECT to_regclass(?) IS NOT NULL AS present,
                            CASE WHEN to_regclass(?) IS NULL THEN false ELSE has_table_privilege(?, to_regclass(?), 'SELECT') OR has_any_column_privilege(?, to_regclass(?), 'SELECT') END AS readable",
                    ["public.{$table}", "public.{$table}", $roles['platform'], "public.{$table}", $roles['platform'], "public.{$table}"],
                );
                if ($r->readable) {
                    $readable[] = $table;
                }
            }
            foreach (self::PII_COLUMNS as $table => $columns) {
                foreach ($columns as $column) {
                    $r = DB::connection('pgsql_owner')->selectOne(
                        "SELECT to_regclass(?) IS NOT NULL AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = ?)
                                AND has_column_privilege(?, to_regclass(?), ?, 'SELECT') AS readable",
                        ["public.{$table}", $table, $column, $roles['platform'], "public.{$table}", $column],
                    );
                    if ($r->readable) {
                        $readable[] = "{$table}.{$column}";
                    }
                }
            }

            return [$readable === [], $readable === [] ? count(self::PII_TABLES).' tables, '.count(self::PII_COLUMNS).' metadata-only tables checked' : 'readable: '.implode(', ', $readable)];
        });

        $this->table(['check', 'result', 'detail'], $this->rows);
        $failed = collect($this->rows)->contains(fn ($r) => $r[1] !== 'PASS');
        $failed ? $this->error('Database role verification FAILED.') : $this->info('Database role verification passed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  callable(): array{0: bool, 1: string}  $probe
     */
    private function check(string $name, callable $probe): void
    {
        try {
            [$ok, $detail] = $probe();
            $this->rows[] = [$name, $ok ? 'PASS' : 'FAIL', $detail];
        } catch (Throwable $e) {
            $this->rows[] = [$name, 'FAIL', 'error: '.class_basename($e)];
        }
    }
}
