<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Automation\AutomationDefinitionError;
use App\Automation\Automations;
use App\Console\Commands\Concerns\FindsAutomation;
use App\Console\Commands\Concerns\RunsInTenantContext;
use Illuminate\Console\Command;

final class AutomationState extends Command
{
    use FindsAutomation, RunsInTenantContext;

    protected $signature = 'automation:state {action : publish|pause|archive} {--tenant= : Tenant slug} {--automation= : Automation id or name}';

    protected $description = 'Publish, pause or archive an automation (audited; publishing never enables sending)';

    public function handle(Automations $automations): int
    {
        return $this->inTenant(function () use ($automations) {
            $id = $this->automationId();
            $actor = Actor::console($this->getName());
            try {
                match ($this->argument('action')) {
                    'publish' => $automations->publish($id, $actor),
                    'pause' => $automations->pause($id, $actor),
                    'archive' => $this->line('Cancelled runs: '.$automations->archive($id, $actor)),
                    default => throw new AutomationDefinitionError('INVALID_TRANSITION', 'Use publish, pause or archive.'),
                };
            } catch (AutomationDefinitionError $e) {
                $this->error("[{$e->reason}] {$e->getMessage()}");

                return self::FAILURE;
            }
            $this->info('Done.');

            return self::SUCCESS;
        });
    }
}
