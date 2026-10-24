<?php

namespace App\Jobs;

use App\Models\TenantIntegration;
use App\Queue\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class ScheduleOneSignalSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public function __construct(?string $tenantId = null)
    {
        $this->forTenant($tenantId);
        $this->onQueue('system');
    }

    public function handle(): void
    {
        $integration = TenantIntegration::query()->where('provider', 'onesignal')->where('capability', 'push')->where('status', 'connected')->first();
        if ($integration !== null) {
            SyncOneSignal::dispatch($integration->id, $this->tenantId);
        }
    }
}
