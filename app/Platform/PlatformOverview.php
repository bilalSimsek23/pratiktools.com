<?php

namespace App\Platform;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin dashboard data. Reads only through the platform connection,
 * whose role has column-level grants on non-personal columns and no access
 * to users, customers, secrets or data keys.
 */
final class PlatformOverview
{
    /**
     * @return Collection<int, \stdClass>
     */
    public function tenants(): Collection
    {
        $db = DB::connection('pgsql_platform');

        $integrations = $db->table('tenant_integrations')
            ->select('tenant_id', 'provider', 'capability', 'status')
            ->get()
            ->groupBy('tenant_id');

        $members = $db->table('tenant_users')
            ->selectRaw('tenant_id, count(*) as total')
            ->where('status', 'active')
            ->groupBy('tenant_id')
            ->pluck('total', 'tenant_id');

        return $db->table('tenants')
            ->select('id', 'name', 'slug', 'status', 'country', 'timezone', 'currency', 'created_at')
            ->orderBy('created_at')
            ->get()
            ->map(function (object $tenant) use ($integrations, $members) {
                $tenant->members = (int) ($members[$tenant->id] ?? 0);
                $tenant->integrations = ($integrations[$tenant->id] ?? collect())->values()->all();

                return $tenant;
            });
    }
}
