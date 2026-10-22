<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Helpers used by migrations to declare row-level security and grants.
 *
 * Every tenant table gets FORCE ROW LEVEL SECURITY, so not even the owner
 * role reads tenant rows without a matching policy, and a policy for the
 * runtime role that compares tenant_id with app_current_tenant(). When no
 * tenant context is set, app_current_tenant() is NULL and the policy matches
 * nothing: reads return no rows and writes fail (fail-closed).
 */
final class RowLevelSecurity
{
    public static function ownerRole(): string
    {
        return self::quote(config('database.roles.owner'));
    }

    public static function appRole(): string
    {
        return self::quote(config('database.roles.app'));
    }

    public static function platformRole(): string
    {
        return self::quote(config('database.roles.platform'));
    }

    /**
     * Enable RLS and add the standard tenant isolation policy for the app role.
     */
    public static function isolateTenantTable(string $table, string $column = 'tenant_id'): void
    {
        $t = self::quote($table);
        $c = self::quote($column);

        DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
        DB::statement(
            "CREATE POLICY tenant_isolation ON {$t} FOR ALL TO ".self::appRole()
            ." USING ({$c} = app_current_tenant()) WITH CHECK ({$c} = app_current_tenant())"
        );
    }

    /**
     * Grant table privileges to the runtime role.
     *
     * @param  list<string>  $privileges
     */
    public static function grantApp(string $table, array $privileges = ['SELECT', 'INSERT', 'UPDATE', 'DELETE']): void
    {
        DB::statement('GRANT '.implode(', ', $privileges).' ON '.self::quote($table).' TO '.self::appRole());
    }

    /**
     * Grant column-level SELECT to the platform (Super Admin) role and let it
     * read every row through a dedicated policy. Columns that hold secrets or
     * personal data are simply never listed.
     *
     * @param  list<string>  $columns
     */
    public static function grantPlatformRead(string $table, array $columns): void
    {
        $t = self::quote($table);
        $cols = implode(', ', array_map(self::quote(...), $columns));

        DB::statement("GRANT SELECT ({$cols}) ON {$t} TO ".self::platformRole());

        if (self::rlsEnabled($table)) {
            DB::statement("CREATE POLICY platform_read ON {$t} FOR SELECT TO ".self::platformRole().' USING (true)');
        }
    }

    public static function rlsEnabled(string $table): bool
    {
        $row = DB::selectOne('SELECT relrowsecurity FROM pg_class WHERE oid = ?::regclass', [$table]);

        return (bool) ($row->relrowsecurity ?? false);
    }

    public static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
