<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Consent\Sync\ConsentSynchronizer;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Models\TenantIntegration;
use Illuminate\Console\Command;

final class BuildConsentRecipients extends Command
{
    use RunsInTenantContext;

    protected $signature = 'consent:build {--tenant= : Tenant slug} {--skip-history : Do not read the store permission history}';

    protected $description = 'Build consent recipients from customers and import store (Ticimax) permission data, read-only';

    public function handle(ConsentSynchronizer $sync): int
    {
        return $this->inTenant(function () use ($sync) {
            $started = microtime(true);
            $built = $sync->buildFromCustomers();
            $this->line(sprintf('customers=%d email_recipients=%d phone_recipients=%d store_observations=%d (%.1fs)',
                $built['customers'], $built['email_recipients'], $built['phone_recipients'], $built['observations'], microtime(true) - $started));

            if (! $this->option('skip-history')) {
                $commerce = TenantIntegration::query()->where('capability', 'commerce')->first();
                if ($commerce === null) {
                    $this->warn('No commerce integration: store permission history skipped.');
                } else {
                    $run = $sync->ticimaxHistory($commerce, actor: Actor::console($this->getName()));
                    $this->line(sprintf('ticimax history: %s read=%d changed=%d api=%d %.1fs%s', $run->status, $run->records_read, $run->records_updated,
                        $run->api_calls, ($run->duration_ms ?? 0) / 1000, $run->error_message ? " [{$run->error_category}] {$run->error_message}" : ''));
                }
            }

            return self::SUCCESS;
        });
    }
}
