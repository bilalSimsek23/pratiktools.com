<?php

namespace App\Console\Commands;

use App\Automation\AutomationMetrics;
use App\Console\Commands\Concerns\FindsAutomation;
use App\Console\Commands\Concerns\RunsInTenantContext;
use Illuminate\Console\Command;

final class AutomationReport extends Command
{
    use FindsAutomation, RunsInTenantContext;

    protected $signature = 'automation:report {--tenant= : Tenant slug} {--automation= : Automation id or name (default: all)} {--days=30}';

    protected $description = 'Automation metrics: runs, planned actions, blocks, duplicates, latency (no personal data)';

    public function handle(AutomationMetrics $metrics): int
    {
        return $this->inTenant(function () use ($metrics) {
            $id = $this->option('automation') ? $this->automationId() : null;
            $this->line(json_encode($metrics->report($id, (int) $this->option('days')), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        });
    }
}
