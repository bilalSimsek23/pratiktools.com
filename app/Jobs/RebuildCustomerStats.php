<?php

namespace App\Jobs;

use App\Commerce\Sync\CustomerStatsBuilder;
use App\Queue\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Recomputes customer_stats for every customer of a tenant. Incremental
 * updates happen during order and cart sync; this is the reconciliation.
 */
final class RebuildCustomerStats implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $timeout = 1800;

    public function __construct(?string $tenantId = null)
    {
        $this->forTenant($tenantId);
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    public function handle(CustomerStatsBuilder $stats): void
    {
        $started = microtime(true);
        $count = $stats->rebuildAll();

        Log::info('commerce.stats.rebuilt', ['customers' => $count, 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
    }
}
