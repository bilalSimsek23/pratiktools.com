<?php

namespace App\Jobs;

use App\Consent\Sync\ConsentSynchronizer;
use App\Consent\Sync\SyncTracker;
use App\Models\IntegrationSyncRun;
use App\Models\TenantIntegration;
use App\Queue\Middleware\SetTenantContext;
use App\Queue\TenantAwareJob;
use App\Queue\TransientRetry;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * One read-only consent sync step on the "sync" queue. The bulk status
 * sync, the recipient build (consent_build, on the commerce integration)
 * and the Ticimax history work in slices and re-queue themselves.
 *
 * The build and the history defer transient errors and try again with
 * backoff (TransientRetry). Started by the Ticimax onboarding, a successful
 * run hands over to the next onboarding step.
 */
final class SyncConsent implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public const KINDS = ['iys_status', 'iys_changes', 'iys_resolve', 'ticimax_history', 'consent_build'];

    public int $tries = 1;

    /**
     * The unique lock expires, so a job lost from the queue (flushed Redis)
     * never blocks starting the same sync again. Overlap is still prevented
     * by the run itself (one running run per entity) and its lock.
     */
    public int $uniqueFor = 3600;

    /**
     * Every kind works in slices of commerce.sync.slice_seconds (plus one
     * provider call), far below this; must stay under the queue's
     * retry_after so a running job is never handed to a second worker.
     */
    public int $timeout = 600;

    public function __construct(
        public readonly string $integrationId,
        public readonly string $kind,
        ?string $tenantId = null,
        public readonly bool $onboarding = false,
        public readonly int $retry = 0,
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

        $slice = (float) config('commerce.sync.slice_seconds');
        $run = match ($this->kind) {
            'iys_status' => $sync->iysStatus($integration, $slice),
            'iys_changes' => tap($sync->iysChanges($integration, 'citizen'), fn () => $sync->iysChanges($integration, 'provider')),
            'iys_resolve' => $sync->iysResolve($integration, sliceSeconds: $slice),
            'ticimax_history' => $sync->ticimaxHistory($integration, sliceSeconds: $slice, deferTransient: true),
            'consent_build' => $sync->buildRun($integration, $slice, deferTransient: true),
            default => throw new \InvalidArgumentException("Unknown consent sync [{$this->kind}]."),
        };

        if (TransientRetry::deferred($run)) {
            $delay = TransientRetry::delay($this->retry);
            $delay === null
                ? app(SyncTracker::class)->fail($run, $integration, TransientRetry::exhausted($run, $this->retry))
                : $this->continue($this->retry + 1, $delay);
        } elseif ($run->status === 'running') {
            // Delayed so this job's overlap lock is released first.
            $this->continue(0, 5);
        } elseif ($run->status === 'succeeded' && $this->onboarding) {
            AdvanceTicimaxOnboarding::dispatch($this->integrationId, $this->tenantId);
        }
    }

    private function continue(int $retry, int $delaySeconds): void
    {
        self::dispatch($this->integrationId, $this->kind, $this->tenantId, $this->onboarding, $retry)->delay(now()->addSeconds($delaySeconds));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new SetTenantContext,
            // A duplicate delivery of the same sync never calls the provider
            // twice: it is dropped while the first one holds the lock.
            (new WithoutOverlapping("consent:{$this->uniqueId()}"))->dontRelease()->expireAfter($this->timeout + 60),
        ];
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
