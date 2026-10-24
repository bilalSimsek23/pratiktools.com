<?php

namespace App\Jobs;

use App\Models\TenantIntegration;
use App\Push\OneSignalSync;
use App\Queue\Middleware\SetTenantContext;
use App\Queue\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * One slice of the OneSignal read-only sync on the "sync" queue.
 */
final class SyncOneSignal implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 1;

    /** A slice plus one export download (bounded by export_max_wait_seconds). */
    public int $timeout = 600;

    public function __construct(public readonly string $integrationId, ?string $tenantId = null)
    {
        $this->forTenant($tenantId);
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return $this->integrationId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new SetTenantContext, (new WithoutOverlapping("onesignal:{$this->integrationId}"))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(OneSignalSync $sync): void
    {
        $integration = TenantIntegration::query()->find($this->integrationId);
        if ($integration === null || in_array($integration->status, ['action_required', 'disabled'], true)) {
            return;
        }

        if ($sync->run($integration, (float) config('commerce.sync.slice_seconds'))->status === 'running') {
            self::dispatch($this->integrationId, $this->tenantId)->delay(now()->addSeconds(15));
        }
    }
}
