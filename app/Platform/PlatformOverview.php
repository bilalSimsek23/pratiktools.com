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

        // Automation counters only (no runs, recipients or customers).
        $automations = $db->table('automations')->selectRaw("tenant_id, count(*) FILTER (WHERE status = 'published') AS published, count(*) AS total")
            ->groupBy('tenant_id')->get()->keyBy('tenant_id');
        $planned = $db->table('automation_metrics_daily')->where('day', '>=', now()->subDays(7)->toDateString())
            ->selectRaw('tenant_id, sum(runs_started) AS runs_started, sum(planned_email) AS planned_email, sum(planned_push) AS planned_push, sum(consent_blocked) AS consent_blocked')
            ->groupBy('tenant_id')->get()->keyBy('tenant_id');

        return $db->table('tenants')
            ->select('id', 'name', 'slug', 'status', 'country', 'timezone', 'currency', 'created_at')
            ->orderBy('created_at')
            ->get()
            ->map(function (object $tenant) use ($integrations, $members, $automations, $planned) {
                $tenant->members = (int) ($members[$tenant->id] ?? 0);
                $tenant->integrations = ($integrations[$tenant->id] ?? collect())->values()->all();
                $tenant->automations = ['published' => (int) ($automations[$tenant->id]->published ?? 0), 'total' => (int) ($automations[$tenant->id]->total ?? 0)];
                $tenant->automation_7d = array_map('intval', (array) ($planned[$tenant->id] ?? ['runs_started' => 0, 'planned_email' => 0, 'planned_push' => 0, 'consent_blocked' => 0]));

                return $tenant;
            });
    }
}
