<?php

namespace App\Jobs;

use App\Commerce\Sync\SyncEntity;
use App\Commerce\Sync\SyncMode;
use App\Models\TenantIntegration;
use App\Queue\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fanned out per tenant by the scheduler: queues the given entity syncs for
 * every connected commerce integration of the tenant.
 */
final class ScheduleCommerceSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    /**
     * @param  array<string, SyncMode>  $entities  entity => mode
     * @param  array<string, int>  $lookbackDays  entity => days a delta window is widened to
     */
    public function __construct(
        public readonly array $entities,
        public readonly array $lookbackDays = [],
        public readonly bool $rebuildStats = false,
        ?string $tenantId = null,
    ) {
        $this->forTenant($tenantId);
        $this->onQueue('system');
    }

    public function handle(): void
    {
        $integrations = TenantIntegration::query()
            ->where('capability', 'commerce')
            ->whereIn('status', ['connected', 'syncing', 'error'])
            ->get();

        foreach ($integrations as $integration) {
            foreach ($this->entities as $entity => $mode) {
                SyncCommerceEntity::dispatch($integration->id, SyncEntity::from($entity), $mode, $this->tenantId, $this->lookbackDays[$entity] ?? null);
            }
        }

        if ($this->rebuildStats && $integrations->isNotEmpty()) {
            RebuildCustomerStats::dispatch($this->tenantId);
        }
    }
}
