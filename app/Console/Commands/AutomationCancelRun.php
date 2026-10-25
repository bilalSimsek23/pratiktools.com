<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Automation\Automations;
use App\Console\Commands\Concerns\RunsInTenantContext;
use Illuminate\Console\Command;

final class AutomationCancelRun extends Command
{
    use RunsInTenantContext;

    protected $signature = 'automation:cancel-run {--tenant= : Tenant slug} {--run= : Run id} {--reason= : Why (audited)}';

    protected $description = 'Cancel one active automation run (audited)';

    public function handle(Automations $automations): int
    {
        if (trim((string) $this->option('reason')) === '') {
            $this->error('--reason is required.');

            return self::INVALID;
        }

        return $this->inTenant(function () use ($automations) {
            $ok = $automations->cancelRun((string) $this->option('run'), (string) $this->option('reason'), Actor::console($this->getName()));
            $ok ? $this->info('Run cancelled.') : $this->error('No active run with this id.');

            return $ok ? self::SUCCESS : self::FAILURE;
        });
    }
}
