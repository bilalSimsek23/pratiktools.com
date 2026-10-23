<?php

use App\Commerce\Sync\SyncMode;
use App\Jobs\PlatformHeartbeat;
use App\Jobs\RecordTenantIntegrationHealth;
use App\Jobs\ScheduleCommerceSync;
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

/*
| Commerce sync. Off unless COMMERCE_SYNC_SCHEDULE_ENABLED=true.
*/

if (config('commerce.schedule.enabled')) {
    foreach (config('commerce.schedule.deltas') as $entity => $cron) {
        Schedule::call(fn () => app(TenantFanout::class)->dispatch(fn (string $tenantId) => new ScheduleCommerceSync([$entity => SyncMode::Delta], tenantId: $tenantId)))
            ->cron($cron)
            ->name("commerce-sync-{$entity}")
            ->withoutOverlapping();
    }

    // Nightly reconciliation: catalog in full, recent orders and customers
    // re-read, stats recomputed from stored data.
    Schedule::call(fn () => app(TenantFanout::class)->dispatch(fn (string $tenantId) => new ScheduleCommerceSync(
        ['categories' => SyncMode::Full, 'products' => SyncMode::Full, 'customers' => SyncMode::Delta, 'orders' => SyncMode::Delta],
        ['customers' => 2, 'orders' => (int) config('commerce.schedule.reconciliation_order_days')],
        rebuildStats: true,
        tenantId: $tenantId,
    )))
        ->cron(config('commerce.schedule.reconciliation'))
        ->name('commerce-reconciliation')
        ->withoutOverlapping();
}
