<?php

use App\Jobs\PlatformHeartbeat;
use App\Jobs\RecordTenantIntegrationHealth;
use App\Queue\TenantFanout;
use Illuminate\Support\Facades\Schedule;

/*
| The scheduler never enters a tenant context itself. Platform jobs run in
| the system context; tenant work is fanned out as one job per tenant, each
| carrying its own tenant_id.
*/

Schedule::job(new PlatformHeartbeat)->everyMinute()->name('platform-heartbeat')->withoutOverlapping();

Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::call(fn () => app(TenantFanout::class)->dispatch(fn (string $tenantId) => new RecordTenantIntegrationHealth($tenantId)))
    ->hourly()
    ->name('tenant-integration-health')
    ->withoutOverlapping();
