<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Automation\DryRun;
use App\Console\Commands\Concerns\FindsAutomation;
use App\Console\Commands\Concerns\RunsInTenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only evaluation of an automation on real data: no provider call,
 * no message, no İYS or Ticimax write (the evaluation runs in a READ ONLY
 * transaction). --days overrides the inactivity of a no_purchase_for
 * trigger to compare windows.
 */
final class AutomationDryRun extends Command
{
    use FindsAutomation, RunsInTenantContext;

    protected $signature = 'automation:dry-run {--tenant= : Tenant slug} {--automation= : Automation id or name} {--days=* : no_purchase_for days to compare} {--max= : candidate limit}';

    protected $description = 'Dry-run an automation (side-effect free) and report outcomes';

    public function handle(DryRun $dryRun): int
    {
        return $this->inTenant(function () use ($dryRun) {
            $id = $this->automationId();
            $actor = Actor::console($this->getName());
            $max = $this->option('max') ? (int) $this->option('max') : null;
            $days = array_map('intval', (array) $this->option('days'));

            if ($days === []) {
                $this->line(json_encode($dryRun->run($id, $actor, maxCandidates: $max), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }
            $trigger = (array) json_decode((string) DB::table('automation_versions')->where('automation_id', $id)->orderByDesc('version')->value('trigger'), true);
            foreach ($days as $d) {
                $trigger['params'] = ['days' => $d] + (array) ($trigger['params'] ?? []);
                $trigger['params']['days'] = $d;
                $this->line("== {$d} days");
                $this->line(json_encode($dryRun->run($id, $actor, $trigger, $max), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            return self::SUCCESS;
        });
    }
}
