<?php

namespace App\Consent\Sync;

use App\Integrations\Errors\ProviderError;
use App\Models\IntegrationError;
use App\Models\IntegrationSyncRun;
use App\Models\TenantIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Run bookkeeping for consent syncs, on the same tables as the commerce
 * sync: one running run per integration and entity (resumable through its
 * page token), categorized errors, and a cursor that only moves on
 * success.
 */
final class SyncTracker
{
    public function open(TenantIntegration $integration, string $entity, string $mode = 'delta', ?CarbonImmutable $windowStart = null, ?CarbonImmutable $windowEnd = null): IntegrationSyncRun
    {
        $running = $this->running($integration, $entity);
        if ($running !== null) {
            return $running;
        }

        try {
            return IntegrationSyncRun::query()->create([
                'integration_id' => $integration->id,
                'entity' => $entity,
                'mode' => $mode,
                'status' => 'running',
                'window_start' => $windowStart,
                'window_end' => $windowEnd ?? now(),
                'started_at' => now(),
            ])->refresh();
        } catch (UniqueConstraintViolationException) {
            return $this->running($integration, $entity) ?? throw new \RuntimeException('Sync run vanished.');
        }
    }

    /**
     * @param  array{read?: int, inserted?: int, updated?: int, skipped?: int, api_calls?: int}  $counts
     */
    public function progress(IntegrationSyncRun $run, array $counts, ?string $pageToken): void
    {
        $run->forceFill([
            'page_token' => $pageToken,
            'records_read' => $run->records_read + ($counts['read'] ?? 0),
            'records_inserted' => $run->records_inserted + ($counts['inserted'] ?? 0),
            'records_updated' => $run->records_updated + ($counts['updated'] ?? 0),
            'records_skipped' => $run->records_skipped + ($counts['skipped'] ?? 0),
            'api_calls' => $run->api_calls + ($counts['api_calls'] ?? 0),
        ])->save();
    }

    public function cursor(TenantIntegration $integration, string $entity): ?object
    {
        return DB::table('integration_sync_cursors')
            ->where('tenant_id', $integration->tenant_id)
            ->where('integration_id', $integration->id)
            ->where('entity', $entity)
            ->first();
    }

    public function succeed(IntegrationSyncRun $run, TenantIntegration $integration, ?string $providerCursor = null, bool $full = false): IntegrationSyncRun
    {
        $finished = CarbonImmutable::now();

        DB::transaction(function () use ($run, $integration, $providerCursor, $full, $finished) {
            $run->forceFill([
                'status' => 'succeeded',
                'finished_at' => $finished,
                'duration_ms' => (int) CarbonImmutable::parse($run->started_at)->diffInMilliseconds($finished),
                'slices' => $run->slices + 1,
            ])->save();

            DB::statement(
                'INSERT INTO integration_sync_cursors (tenant_id, integration_id, entity, last_window_end, last_full_sync_at, last_success_at, provider_cursor, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, now(), now())
                 ON CONFLICT (tenant_id, integration_id, entity) DO UPDATE SET
                    last_window_end = GREATEST(integration_sync_cursors.last_window_end, EXCLUDED.last_window_end),
                    last_full_sync_at = COALESCE(EXCLUDED.last_full_sync_at, integration_sync_cursors.last_full_sync_at),
                    last_success_at = EXCLUDED.last_success_at,
                    provider_cursor = COALESCE(EXCLUDED.provider_cursor, integration_sync_cursors.provider_cursor),
                    updated_at = now()',
                [$integration->tenant_id, $integration->id, $run->entity, $run->window_end, $full ? $finished : null, $finished, $providerCursor],
            );

            if (! IntegrationSyncRun::query()->where('integration_id', $integration->id)->where('status', 'running')->whereKeyNot($run->id)->exists()) {
                $integration->forceFill(['status' => 'connected', 'last_error' => null])->save();
            }
        });

        Log::info('consent.sync.succeeded', ['run_id' => $run->id, 'entity' => $run->entity, 'records_read' => $run->records_read, 'api_calls' => $run->api_calls]);

        return $run;
    }

    /**
     * Records a non-fatal finding (for example a changes gap) as an
     * integration error without failing the run.
     *
     * @param  array<string, mixed>  $context
     */
    public function note(TenantIntegration $integration, ?IntegrationSyncRun $run, string $category, string $operation, string $message, array $context = []): void
    {
        IntegrationError::query()->create([
            'integration_id' => $integration->id,
            'sync_run_id' => $run?->id,
            'category' => $category,
            'operation' => $operation,
            'message' => $message,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }

    public function fail(IntegrationSyncRun $run, TenantIntegration $integration, Throwable $e): IntegrationSyncRun
    {
        $error = $e instanceof ProviderError ? $e : new ProviderError(ProviderError::PERMANENT, 'Unexpected '.class_basename($e).' during consent sync.', previous: $e);

        DB::transaction(function () use ($run, $integration, $error) {
            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'duration_ms' => (int) CarbonImmutable::parse($run->started_at)->diffInMilliseconds(now()),
                'errors' => $run->errors + 1,
                'error_category' => $error->category,
                'error_message' => $error->getMessage(),
            ])->save();
            $this->note($integration, $run, $error->category, (string) $error->operation, $error->getMessage(), ['entity' => $run->entity]);
            $integration->forceFill([
                'status' => $error->category === ProviderError::AUTH ? 'action_required' : 'error',
                'last_error' => $error->getMessage(),
            ])->save();
        });

        Log::warning('consent.sync.failed', [
            'run_id' => $run->id, 'entity' => $run->entity, 'error_category' => $error->category,
            'error_class' => $e::class, 'error_at' => basename($e->getFile()).':'.$e->getLine(),
        ]);

        return $run;
    }

    private function running(TenantIntegration $integration, string $entity): ?IntegrationSyncRun
    {
        return IntegrationSyncRun::query()
            ->where('integration_id', $integration->id)
            ->where('entity', $entity)
            ->where('status', 'running')
            ->first();
    }
}
