<?php

namespace App\Jobs;

use App\Commerce\Sync\CustomerStatsBuilder;
use App\Queue\Middleware\SetTenantContext;
use App\Queue\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Recomputes customer_stats for every customer of a tenant. Incremental
 * updates happen during order and cart sync; this is the reconciliation.
 */
final class RebuildCustomerStats implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    /**
     * Batches of 5,000 customers; IGS (147k) needs about two minutes. Must
     * stay under the queue's retry_after.
     */
    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(?string $tenantId = null)
    {
        $this->forTenant($tenantId);
        $this->onQueue('sync');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new SetTenantContext,
            (new WithoutOverlapping("stats:{$this->tenantId}"))->dontRelease()->expireAfter($this->timeout + 60),
        ];
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
