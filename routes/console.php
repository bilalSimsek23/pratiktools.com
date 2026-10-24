<?php

use App\Commerce\Sync\SyncMode;
use App\Jobs\PlatformHeartbeat;
use App\Jobs\RecordTenantIntegrationHealth;
use App\Jobs\ScheduleCommerceSync;
use App\Jobs\ScheduleConsentSync;
use App\Jobs\ScheduleOneSignalSync;
use App\Queue\TenantFanout;
use Illuminate\Support\Facades\Schedule;

/*
| The scheduler never enters a tenant context itself. Platform jobs run in
| the system context; tenant work is fanned out as one job per tenant, each
| carrying its own tenant_id.
|
| Every entry runs on one server (a cache lock in Redis) and never
| overlaps itself, so several App/Worker replicas with the scheduler
| enabled cannot dispatch the same work twice.
*/

Schedule::job(new PlatformHeartbeat)->everyMinute()->name('platform-heartbeat')->onOneServer()->withoutOverlapping();

Schedule::command('horizon:snapshot')->everyFiveMinutes()->onOneServer()->withoutOverlapping();

Schedule::call(fn () => app(TenantFanout::class)->dispatch(fn (string $tenantId) => new RecordTenantIntegrationHealth($tenantId)))
    ->hourly()
    ->name('tenant-integration-health')
    ->onOneServer()
    ->withoutOverlapping();

/*
| Commerce sync. Off unless COMMERCE_SYNC_SCHEDULE_ENABLED=true.
*/

if (config('commerce.schedule.enabled')) {
    foreach (config('commerce.schedule.deltas') as $entity => $cron) {
        Schedule::call(fn () => app(TenantFanout::class)->dispatch(fn (string $tenantId) => new ScheduleCommerceSync([$entity => SyncMode::Delta], tenantId: $tenantId)))
            ->cron($cron)
            ->name("commerce-sync-{$entity}")
            ->onOneServer()
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
        ->onOneServer()
        ->withoutOverlapping();
}

/*
| Consent shadow sync (read-only). Off unless CONSENT_SYNC_SCHEDULE_ENABLED=true.
*/

if (config('consent.schedule.enabled')) {
    foreach (['iys_changes' => 'changes', 'iys_status' => 'status', 'ticimax_history' => 'ticimax_history'] as $kind => $key) {
        Schedule::call(fn () => app(TenantFanout::class)->dispatch(fn (string $tenantId) => new ScheduleConsentSync($kind, $tenantId)))
            ->cron(config("consent.schedule.{$key}"))
            ->name("consent-sync-{$kind}")
            ->onOneServer()
            ->withoutOverlapping();
    }
}

/*
| OneSignal read-only sync. Off unless ONESIGNAL_SYNC_SCHEDULE_ENABLED=true.
*/

if (config('push.onesignal.sync_schedule_enabled')) {
    Schedule::call(fn () => app(TenantFanout::class)->dispatch(fn (string $tenantId) => new ScheduleOneSignalSync($tenantId)))
        ->cron(config('push.onesignal.sync_schedule'))
        ->name('onesignal-sync')
        ->onOneServer()
        ->withoutOverlapping();
}
