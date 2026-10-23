<?php

namespace App\Jobs;

use App\Consent\Sync\ConsentSynchronizer;
use App\Consent\Sync\SyncTracker;
use App\Models\IntegrationSyncRun;
use App\Models\TenantIntegration;
use App\Queue\TenantAwareJob;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * One read-only consent sync step on the "sync" queue. The bulk status
 * sync works in slices and re-queues itself.
 */
final class SyncConsent implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public const KINDS = ['iys_status', 'iys_changes', 'iys_resolve', 'ticimax_history'];

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly string $integrationId,
        public readonly string $kind,
        ?string $tenantId = null,
    ) {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("Unknown consent sync [{$kind}].");
        }
        $this->forTenant($tenantId);
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return "{$this->integrationId}:{$this->kind}";
    }

    public function handle(ConsentSynchronizer $sync): void
    {
        $integration = TenantIntegration::query()->find($this->integrationId);
        if ($integration === null || in_array($integration->status, ['action_required', 'disabled'], true)) {
            return;
        }

        $run = match ($this->kind) {
            'iys_status' => $sync->iysStatus($integration, (float) config('commerce.sync.slice_seconds')),
            'iys_changes' => tap($sync->iysChanges($integration, 'citizen'), fn () => $sync->iysChanges($integration, 'provider')),
            'iys_resolve' => $sync->iysResolve($integration),
            'ticimax_history' => $sync->ticimaxHistory($integration),
            default => throw new \InvalidArgumentException("Unknown consent sync [{$this->kind}]."),
        };

        if ($run->status === 'running') {
            self::dispatch($this->integrationId, $this->kind, $this->tenantId);
        }
    }

    public function failed(?Throwable $e): void
    {
        app(TenantContext::class)->run($this->tenantId, function () use ($e) {
            $integration = TenantIntegration::query()->find($this->integrationId);
            $run = $integration === null ? null : IntegrationSyncRun::query()
                ->where('integration_id', $integration->id)->where('status', 'running')->first();
            if ($integration !== null && $run !== null) {
                app(SyncTracker::class)->fail($run, $integration, $e ?? new \RuntimeException('Consent sync job failed.'));
            }
        });
    }
}
