<?php

namespace App\Jobs;

use App\Models\TenantIntegration;
use App\Queue\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled per tenant through TenantFanout. Emits the tenant's integration
 * status counts as a structured log/metric line (no secrets, no PII).
 */
final class RecordTenantIntegrationHealth implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public function __construct(?string $tenantId = null)
    {
        $this->forTenant($tenantId);
        $this->onQueue('system');
    }

    public function handle(): void
    {
        $counts = TenantIntegration::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        Log::info('tenant.integration_health', ['statuses' => $counts]);
    }
}
