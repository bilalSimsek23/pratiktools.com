<?php

namespace App\Jobs;

use App\Commerce\Sync\SyncEntity;
use App\Commerce\Sync\SyncMode;
use App\Commerce\Sync\SyncRunner;
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
 * One time slice of an entity sync. When the slice ends before the window
 * is complete, the job queues its continuation at the back of the "sync"
 * queue, so one large tenant never blocks the others.
 */
final class SyncCommerceEntity implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly string $integrationId,
        public readonly SyncEntity $entity,
        public readonly SyncMode $mode = SyncMode::Delta,
        ?string $tenantId = null,
        public readonly ?int $lookbackDays = null,
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

        $run = $runner->run($integration, $this->entity, $this->mode, (float) config('commerce.sync.slice_seconds'), lookbackDays: $this->lookbackDays);

        if ($run->status === 'running') {
            self::dispatch($this->integrationId, $this->entity, $this->mode, $this->tenantId, $this->lookbackDays);
        }
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
