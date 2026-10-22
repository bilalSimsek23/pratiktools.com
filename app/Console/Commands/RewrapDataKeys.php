<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Security\Vault\SecretVault;
use Illuminate\Console\Command;

final class RewrapDataKeys extends Command
{
    use RunsInTenantContext;

    protected $signature = 'vault:rewrap {--tenant= : Tenant slug}';

    protected $description = 'Re-wrap a tenant\'s data keys with the current KEK (after a KEK rotation)';

    public function handle(SecretVault $vault): int
    {
        $count = $this->inTenant(fn () => $vault->rewrapDataKeys(Actor::console($this->getName())));
        $this->info("{$count} data key(s) re-wrapped.");

        return self::SUCCESS;
    }
}
