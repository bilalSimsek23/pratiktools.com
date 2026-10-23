<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Security\Vault\KekMaintenance;
use App\Security\Vault\SecretVault;
use Illuminate\Console\Command;

final class RewrapDataKeys extends Command
{
    use RunsInTenantContext;

    protected $signature = 'vault:rewrap {--tenant= : Tenant slug} {--all : Every tenant with data keys under an old KEK}';

    protected $description = 'Re-wrap data keys with the current KEK after a KEK rotation (secrets are not re-encrypted)';

    public function handle(SecretVault $vault, KekMaintenance $maintenance): int
    {
        $actor = Actor::console($this->getName());

        if ($this->option('all')) {
            $result = $maintenance->rewrapAll($actor, fn (string $tenant, int $keys) => $this->line("tenant {$tenant}: {$keys} data key(s) re-wrapped"));
            $this->info("{$result['keys']} data key(s) re-wrapped in {$result['tenants']} tenant(s). Run vault:verify-kek next.");

            return self::SUCCESS;
        }

        $count = $this->inTenant(fn () => $vault->rewrapDataKeys($actor));
        $this->info("{$count} data key(s) re-wrapped.");

        return self::SUCCESS;
    }
}
