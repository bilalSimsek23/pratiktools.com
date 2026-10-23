<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Commerce\Sync\SyncEntity;
use App\Commerce\Sync\SyncMode;
use App\Commerce\Sync\SyncRunner;
use App\Console\Commands\Concerns\FindsCommerceIntegration;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Jobs\SyncCommerceEntity;
use Illuminate\Console\Command;

final class SyncCommerce extends Command
{
    use FindsCommerceIntegration, RunsInTenantContext;

    protected $signature = 'commerce:sync
        {--tenant= : Tenant slug}
        {--entity=all : categories, products, customers, orders, carts or all}
        {--mode=delta : delta or full}
        {--inline : Run in this process instead of queueing}
        {--lookback-days= : Widen a delta window to this many days}';

    protected $description = 'Read-only sync from the tenant\'s commerce platform';

    public function handle(SyncRunner $runner): int
    {
        $mode = SyncMode::from((string) $this->option('mode'));
        $entities = $this->option('entity') === 'all' ? SyncEntity::ordered() : [SyncEntity::from((string) $this->option('entity'))];
        $lookback = $this->option('lookback-days') === null ? null : (int) $this->option('lookback-days');

        return $this->inTenant(function (string $tenantId) use ($runner, $mode, $entities, $lookback) {
            $integration = $this->commerceIntegration();
            $failed = false;

            foreach ($entities as $entity) {
                if (! $this->option('inline')) {
                    SyncCommerceEntity::dispatch($integration->id, $entity, $mode, $tenantId, $lookback);
                    $this->line("Queued {$entity->value} ({$mode->value}).");

                    continue;
                }

                $run = $runner->run($integration, $entity, $mode, actor: Actor::console($this->getName()), lookbackDays: $lookback);
                $this->line(sprintf(
                    '%-10s %-5s %-9s read=%d ins=%d upd=%d skip=%d api=%d %.1fs%s',
                    $entity->value, $run->mode, $run->status, $run->records_read, $run->records_inserted, $run->records_updated,
                    $run->records_skipped, $run->api_calls, ($run->duration_ms ?? 0) / 1000,
                    $run->error_category ? " [{$run->error_category}] {$run->error_message}" : '',
                ));
                $failed = $failed || $run->status === 'failed';
                $integration->refresh();
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        });
    }
}
