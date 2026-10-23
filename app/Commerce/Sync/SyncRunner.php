<?php

namespace App\Commerce\Sync;

use App\Audit\Actor;
use App\Commerce\CommerceSourceFactory;
use App\Commerce\Contracts\CommerceSource;
use App\Commerce\Data\RecordPage;
use App\Commerce\Data\SyncWindow;
use App\Integrations\Errors\ProviderError;
use App\Models\IntegrationError;
use App\Models\IntegrationSyncRun;
use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one entity sync for one integration, page by page.
 *
 * - A run reads one fixed window. Full runs start at the backfill start,
 *   delta runs at the cursor minus the entity's overlap; every window ends
 *   a safety lag before "now".
 * - Progress (page token and counters) is saved after every stored page, so
 *   a run interrupted by a slice limit, a deploy or a crash resumes where
 *   it stopped. Re-reading a page is harmless: every write is idempotent.
 * - The cursor moves only when the whole window was stored, and never
 *   backwards. A failed run leaves the cursor alone; the next run re-reads
 *   the same window.
 */
final class SyncRunner
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CommerceSourceFactory $sources,
        private readonly CustomerUpserter $customers,
        private readonly CatalogUpserter $catalog,
        private readonly OrderUpserter $orders,
        private readonly CartUpserter $carts,
        private readonly CustomerStatsBuilder $stats,
    ) {}

    /**
     * Returns the run; status "running" means the slice ended before the
     * window was complete and the caller should continue it.
     *
     * $lookbackDays widens a delta window (reconciliation re-reads recent
     * days to catch changes a delta filter may have missed).
     */
    public function run(TenantIntegration $integration, SyncEntity $entity, SyncMode $mode, ?float $sliceSeconds = null, ?Actor $actor = null, ?int $lookbackDays = null): IntegrationSyncRun
    {
        $this->context->requireTenant();
        $run = $this->openRun($integration, $entity, $mode, $lookbackDays);
        $sliceStarted = microtime(true);

        try {
            $integration->forceFill(['status' => 'syncing'])->save();
            $source = $this->sources->for($integration, "sync:{$entity->value}", $actor ?? Actor::job(self::class));
            $window = new SyncWindow(
                $run->window_start === null ? null : CarbonImmutable::parse($run->window_start),
                CarbonImmutable::parse($run->window_end),
                $run->mode === SyncMode::Full->value,
            );

            while (true) {
                $page = $this->fetch($source, $entity, $window, $run->page_token);
                $result = new UpsertResult;
                $this->store($entity, $page, $integration->provider, $window->full, $result);

                $run->forceFill([
                    'page_token' => $page->nextPageToken,
                    'records_read' => $run->records_read + count($page->records),
                    'records_inserted' => $run->records_inserted + $result->inserted,
                    'records_updated' => $run->records_updated + $result->updated,
                    'records_skipped' => $run->records_skipped + $result->skipped,
                    'api_calls' => $run->api_calls + $page->apiCalls,
                ])->save();

                if ($page->nextPageToken === null) {
                    return $this->succeed($run, $integration, $entity);
                }

                if ($sliceSeconds !== null && microtime(true) - $sliceStarted >= $sliceSeconds) {
                    $run->forceFill(['slices' => $run->slices + 1])->save();

                    return $run;
                }
            }
        } catch (Throwable $e) {
            return $this->fail($run, $integration, $e);
        }
    }

    /**
     * Marks the integration's running run of this entity as failed. Used
     * when the queue gives up on a job.
     */
    public function abandon(TenantIntegration $integration, SyncEntity $entity, Throwable $e): void
    {
        $run = IntegrationSyncRun::query()
            ->where('integration_id', $integration->id)
            ->where('entity', $entity->value)
            ->where('status', 'running')
            ->first();

        if ($run !== null) {
            $this->fail($run, $integration, $e);
        }
    }

    private function openRun(TenantIntegration $integration, SyncEntity $entity, SyncMode $mode, ?int $lookbackDays): IntegrationSyncRun
    {
        $running = IntegrationSyncRun::query()
            ->where('integration_id', $integration->id)
            ->where('entity', $entity->value)
            ->where('status', 'running')
            ->first();

        if ($running !== null) {
            return $running;
        }

        $cursor = DB::table('integration_sync_cursors')
            ->where('tenant_id', $integration->tenant_id)
            ->where('integration_id', $integration->id)
            ->where('entity', $entity->value)
            ->first();

        $to = CarbonImmutable::now()->subSeconds((int) config('commerce.sync.safety_lag_seconds'))->startOfSecond();
        $lastEnd = $cursor?->last_window_end === null ? null : CarbonImmutable::parse($cursor->last_window_end);
        $full = $mode === SyncMode::Full || $lastEnd === null || $entity === SyncEntity::Categories;

        $from = $full
            ? match ($entity) {
                SyncEntity::Orders => $to->subDays((int) config('commerce.sync.order_backfill_days')),
                SyncEntity::Carts => $to->subDays((int) config('commerce.sync.cart_backfill_days')),
                default => null,
            }
        : $lastEnd->subMinutes($entity->overlapMinutes());

        if (! $full && $entity === SyncEntity::Carts) {
            // Carts are filterable by creation date only: re-read a fixed
            // look-back so carts changed after creation are seen again.
            $from = $from->min($to->subHours((int) config('commerce.sync.cart_lookback_hours')));
        }

        if (! $full && $lookbackDays !== null) {
            $from = $from->min($to->subDays($lookbackDays));
        }

        try {
            return IntegrationSyncRun::query()->create([
                'integration_id' => $integration->id,
                'entity' => $entity->value,
                'mode' => $full ? SyncMode::Full->value : SyncMode::Delta->value,
                'status' => 'running',
                'window_start' => $from,
                'window_end' => $to,
                'started_at' => now(),
            ])->refresh();
        } catch (UniqueConstraintViolationException) {
            // Another worker opened the run between our check and insert.
            return IntegrationSyncRun::query()
                ->where('integration_id', $integration->id)
                ->where('entity', $entity->value)
                ->where('status', 'running')
                ->firstOrFail();
        }
    }

    /**
     * @return RecordPage<mixed>
     */
    private function fetch(CommerceSource $source, SyncEntity $entity, SyncWindow $window, ?string $token): RecordPage
    {
        return match ($entity) {
            SyncEntity::Categories => $source->categories($token),
            SyncEntity::Products => $source->products($window, $token),
            SyncEntity::Customers => $source->customers($window, $token),
            SyncEntity::Orders => $source->orders($window, $token),
            SyncEntity::Carts => $source->carts($window, $token),
        };
    }

    /**
     * @param  RecordPage<mixed>  $page
     */
    private function store(SyncEntity $entity, RecordPage $page, string $source, bool $backfill, UpsertResult $result): void
    {
        match ($entity) {
            SyncEntity::Categories => $this->catalog->categories($page->records, $result),
            SyncEntity::Products => $this->catalog->products($page->records, $result),
            SyncEntity::Customers => $this->customers->upsert($page->records, $source, $backfill, $result),
            SyncEntity::Orders => $this->orders->upsert($page->records, $source, $backfill, $result),
            SyncEntity::Carts => $this->carts->upsert($page->records, $source, $backfill, $result),
        };

        if (in_array($entity, [SyncEntity::Orders, SyncEntity::Carts], true) && $result->touchedCustomers !== []) {
            $this->stats->rebuild(array_keys($result->touchedCustomers));
        }
    }

    private function succeed(IntegrationSyncRun $run, TenantIntegration $integration, SyncEntity $entity): IntegrationSyncRun
    {
        $finished = CarbonImmutable::now();

        DB::transaction(function () use ($run, $integration, $entity, $finished) {
            $run->forceFill([
                'status' => 'succeeded',
                'finished_at' => $finished,
                'duration_ms' => (int) CarbonImmutable::parse($run->started_at)->diffInMilliseconds($finished),
                'slices' => $run->slices + 1,
            ])->save();

            DB::statement(
                'INSERT INTO integration_sync_cursors (tenant_id, integration_id, entity, last_window_end, last_full_sync_at, last_success_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, now(), now())
                 ON CONFLICT (tenant_id, integration_id, entity) DO UPDATE SET
                    last_window_end = GREATEST(integration_sync_cursors.last_window_end, EXCLUDED.last_window_end),
                    last_full_sync_at = COALESCE(EXCLUDED.last_full_sync_at, integration_sync_cursors.last_full_sync_at),
                    last_success_at = EXCLUDED.last_success_at,
                    updated_at = now()',
                [
                    $integration->tenant_id, $integration->id, $entity->value, $run->window_end,
                    $run->mode === SyncMode::Full->value ? $finished : null, $finished,
                ],
            );

            if (! $this->hasOtherRunning($integration, $run)) {
                $integration->forceFill(['status' => 'connected', 'last_error' => null])->save();
            }
        });

        Log::info('commerce.sync.succeeded', $this->logContext($run));

        return $run;
    }

    private function fail(IntegrationSyncRun $run, TenantIntegration $integration, Throwable $e): IntegrationSyncRun
    {
        $error = $e instanceof ProviderError
            ? $e
            : new ProviderError(ProviderError::PERMANENT, 'Unexpected '.class_basename($e).' while storing synced data.', previous: $e);

        DB::transaction(function () use ($run, $integration, $error) {
            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'duration_ms' => (int) CarbonImmutable::parse($run->started_at)->diffInMilliseconds(now()),
                'errors' => $run->errors + 1,
                'error_category' => $error->category,
                'error_message' => $error->getMessage(),
            ])->save();

            IntegrationError::query()->create([
                'integration_id' => $integration->id,
                'sync_run_id' => $run->id,
                'category' => $error->category,
                'operation' => $error->operation,
                'message' => $error->getMessage(),
                'context' => ['entity' => $run->entity, 'mode' => $run->mode, 'page_token' => $run->page_token],
                'occurred_at' => now(),
            ]);

            $integration->forceFill([
                'status' => $error->category === ProviderError::AUTH ? 'action_required' : 'error',
                'last_error' => $error->getMessage(),
            ])->save();
        });

        // Not report($e): database exceptions carry bindings (personal data)
        // in their message. The class and location are enough to find it.
        Log::warning('commerce.sync.failed', $this->logContext($run) + [
            'error_category' => $error->category,
            'error_class' => $e::class,
            'error_at' => basename($e->getFile()).':'.$e->getLine(),
        ]);

        return $run;
    }

    private function hasOtherRunning(TenantIntegration $integration, IntegrationSyncRun $run): bool
    {
        return IntegrationSyncRun::query()
            ->where('integration_id', $integration->id)
            ->where('status', 'running')
            ->whereKeyNot($run->id)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(IntegrationSyncRun $run): array
    {
        return [
            'run_id' => $run->id,
            'entity' => $run->entity,
            'mode' => $run->mode,
            'records_read' => $run->records_read,
            'api_calls' => $run->api_calls,
            'duration_ms' => $run->duration_ms,
        ];
    }
}
