<?php

namespace App\Console\Commands;

use App\Automation\AutomationEngine;
use App\Console\Commands\Concerns\RunsInTenantContext;
use Illuminate\Console\Command;

/**
 * Runs one bounded engine tick for a tenant now (the same work the
 * scheduler does): scan triggers, evaluate time triggers, advance due
 * runs. Produces planned actions only; nothing is sent.
 */
final class AutomationResumeDue extends Command
{
    use RunsInTenantContext;

    protected $signature = 'automation:resume-due {--tenant= : Tenant slug} {--no-scan : only advance due runs}';

    protected $description = 'Advance due automation runs now (bounded; planned actions only)';

    public function handle(AutomationEngine $engine): int
    {
        return $this->inTenant(function () use ($engine) {
            $this->line(json_encode($engine->tick(scan: ! $this->option('no-scan')), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        });
    }
}
