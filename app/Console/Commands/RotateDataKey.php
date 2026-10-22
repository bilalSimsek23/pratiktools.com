<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Security\Vault\SecretVault;
use Illuminate\Console\Command;

final class RotateDataKey extends Command
{
    use RunsInTenantContext;

    protected $signature = 'vault:rotate-data-key {--tenant= : Tenant slug}';

    protected $description = 'Create a new data key for a tenant and re-encrypt its secrets';

    public function handle(SecretVault $vault): int
    {
        $version = $this->inTenant(fn () => $vault->rotateDataKey(Actor::console($this->getName())));
        $this->info("Data key rotated to version {$version}.");

        return self::SUCCESS;
    }
}
