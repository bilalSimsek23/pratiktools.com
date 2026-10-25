<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsInTenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AutomationList extends Command
{
    use RunsInTenantContext;

    protected $signature = 'automation:list {--tenant= : Tenant slug}';

    protected $description = 'List automations with state, version and trigger';

    public function handle(): int
    {
        return $this->inTenant(function () {
            $rows = DB::table('automations as a')->leftJoin('automation_versions as v', fn ($j) => $j->on('v.id', '=', 'a.published_version_id')->on('v.tenant_id', '=', 'a.tenant_id'))
                ->orderBy('a.name')->get(['a.id', 'a.name', 'a.status', 'v.version', 'v.trigger',
                    DB::raw("(SELECT count(*) FROM automation_runs r WHERE r.tenant_id = a.tenant_id AND r.automation_id = a.id AND r.status = 'waiting') AS waiting"),
                    DB::raw('(SELECT max(version) FROM automation_versions x WHERE x.tenant_id = a.tenant_id AND x.automation_id = a.id) AS latest')]);
            $this->table(['id', 'name', 'status', 'published v', 'latest v', 'trigger', 'waiting'], $rows->map(fn ($r) => [
                $r->id, $r->name, $r->status, $r->version ?? '-', $r->latest, $r->trigger === null ? '-' : (json_decode($r->trigger, true)['type'] ?? '-'), $r->waiting,
            ])->all());

            return self::SUCCESS;
        });
    }
}
