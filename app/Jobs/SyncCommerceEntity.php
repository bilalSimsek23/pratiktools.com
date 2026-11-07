<?php

namespace App\Jobs;

use App\Commerce\Sync\SyncEntity;
use App\Commerce\Sync\SyncMode;
use App\Commerce\Sync\SyncRunner;
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
 * One time slice of an entity sync. When the slice ends before the window
 * is complete, the job queues its continuation at the back of the "sync"
 * queue, so one large tenant never blocks the others.
 *
 * A transient error (dropped Redis or database connection, provider
 * timeout) leaves the run at its saved page; the job tries again after
 * commerce.sync.retry_backoff_seconds and fails the run when they are used
 * up. Started by the Ticimax onboarding ($onboarding), a successful run
 * hands over to the next onboarding step.
 */
final class SyncCommerceEntity implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 1;

    /**
     * The unique lock expires, so a job lost from the queue (flushed Redis)
     * never blocks starting the same sync again. Overlap is still prevented
     * by the run itself (one running run per entity) and its lock.
     */
    public int $uniqueFor = 3600;

    /**
     * A slice works for commerce.sync.slice_seconds plus one page; this
     * must stay under the queue's retry_after.
     */
    public int $timeout = 300;

    public function __construct(
        public readonly string $integrationId,
        public readonly SyncEntity $entity,
        public readonly SyncMode $mode = SyncMode::Delta,
        ?string $tenantId = null,
        public readonly ?int $lookbackDays = null,
        public readonly bool $onboarding = false,
        public readonly int $retry = 0,
    ) {
        $this->forTenant($tenantId);
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return "{$this->integrationId}:{$this->entity->value}";
    }

    public function handle(SyncRunner $runner): void
    {
        $integration = TenantIntegration::query()->find($this->integrationId);

        if ($integration === null || ! in_array($integration->status, ['connected', 'syncing', 'error', 'pending'], true)) {
            // Disabled, removed or waiting for new credentials.
            return;
        }

        $run = $runner->run($integration, $this->entity, $this->mode, (float) config('commerce.sync.slice_seconds'), lookbackDays: $this->lookbackDays, deferTransient: true);

        if (TransientRetry::deferred($run)) {
            $delay = TransientRetry::delay($this->retry);
            $delay === null
                ? $runner->abandon($integration, $this->entity, TransientRetry::exhausted($run, $this->retry))
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
        self::dispatch($this->integrationId, $this->entity, $this->mode, $this->tenantId, $this->lookbackDays, $this->onboarding, $retry)->delay(now()->addSeconds($delaySeconds));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new SetTenantContext,
            // A duplicate delivery never reads the same window twice at the
            // provider: it is dropped while the first one holds the lock.
            (new WithoutOverlapping("commerce:{$this->uniqueId()}"))->dontRelease()->expireAfter($this->timeout + 60),
        ];
    }

    /**
     * Runs outside the job middleware, so it enters the tenant context
     * itself before touching tenant tables.
     */
    public function failed(?Throwable $e): void
    {
        app(TenantContext::class)->run($this->tenantId, function () use ($e) {
            $integration = TenantIntegration::query()->find($this->integrationId);

            if ($integration !== null) {
                app(SyncRunner::class)->abandon($integration, $this->entity, $e ?? new \RuntimeException('Sync job failed.'));
            }
        });
    }
}
