<?php

namespace App\Jobs;

use App\Models\TenantIntegration;
use App\Queue\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fanned out per tenant: queues the given consent sync kind for the
 * tenant's İYS (or, for store history, commerce) integration.
 */
final class ScheduleConsentSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public function __construct(public readonly string $kind, ?string $tenantId = null)
    {
        $this->forTenant($tenantId);
        $this->onQueue('system');
    }

    public function handle(): void
    {
        [$provider, $capability] = $this->kind === 'ticimax_history' ? ['ticimax', 'commerce'] : ['iys', 'consent'];

        $integration = TenantIntegration::query()->where('provider', $provider)->where('capability', $capability)
            ->whereIn('status', ['connected', 'syncing', 'error'])->first();

        if ($integration !== null) {
            SyncConsent::dispatch($integration->id, $this->kind, $this->tenantId);
        }
    }
}
