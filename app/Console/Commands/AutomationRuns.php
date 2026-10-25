<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsAutomation;
use App\Console\Commands\Concerns\RunsInTenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AutomationRuns extends Command
{
    use FindsAutomation, RunsInTenantContext;

    protected $signature = 'automation:runs {--tenant= : Tenant slug} {--automation= : Automation id or name} {--status= : Filter by status} {--limit=20}';

    protected $description = 'Recent runs of an automation (ids, status, step, exit reason; no personal data)';

    public function handle(): int
    {
        return $this->inTenant(function () {
            $q = DB::table('automation_runs')->where('automation_id', $this->automationId())->orderByDesc('started_at')->limit((int) $this->option('limit'));
            if ($this->option('status')) {
                $q->where('status', $this->option('status'));
            }
            $this->table(['run', 'status', 'trigger', 'step', 'resume_at', 'exit_reason', 'started', 'completed'], $q->get()->map(fn ($r) => [
                $r->id, $r->status, $r->trigger_type, $r->current_step, $r->resume_at, $r->exit_reason, $r->started_at, $r->completed_at,
            ])->all());

            return self::SUCCESS;
        });
    }
}
