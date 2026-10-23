<?php

namespace App\Commerce;

use App\Commerce\Sync\SyncEntity;
use App\Models\IntegrationError;
use App\Models\IntegrationSyncRun;
use App\Models\TenantIntegration;
use Illuminate\Support\Facades\DB;

/**
 * Sync health of one integration, as shown to the tenant: overall status,
 * per entity the cursor and the latest run, and the most recent errors.
 * Contains no personal data and no secrets.
 */
final class SyncHealth
{
    /**
     * @return array<string, mixed>
     */
    public function for(TenantIntegration $integration): array
    {
        $cursors = DB::table('integration_sync_cursors')
            ->where('tenant_id', $integration->tenant_id)
            ->where('integration_id', $integration->id)
            ->get()
            ->keyBy('entity');

        $entities = [];
        foreach (SyncEntity::ordered() as $entity) {
            $last = IntegrationSyncRun::query()
                ->where('integration_id', $integration->id)
                ->where('entity', $entity->value)
                ->orderByDesc('started_at')
                ->first();
            $cursor = $cursors[$entity->value] ?? null;

            $entities[$entity->value] = [
                'cursor' => $cursor?->last_window_end,
                'last_success_at' => $cursor?->last_success_at,
                'last_full_sync_at' => $cursor?->last_full_sync_at,
                'last_run' => $last === null ? null : [
                    'id' => $last->id,
                    'mode' => $last->mode,
                    'status' => $last->status,
                    'started_at' => $last->started_at->toIso8601String(),
                    'finished_at' => $last->finished_at?->toIso8601String(),
                    'duration_ms' => $last->duration_ms,
                    'records_read' => $last->records_read,
                    'inserted' => $last->records_inserted,
                    'updated' => $last->records_updated,
                    'skipped' => $last->records_skipped,
                    'api_calls' => $last->api_calls,
                    'slices' => $last->slices,
                    'error_category' => $last->error_category,
                ],
            ];
        }

        return [
            'integration_id' => $integration->id,
            'provider' => $integration->provider,
            'status' => $this->status($integration),
            'last_error' => $integration->last_error,
            'entities' => $entities,
            'recent_errors' => IntegrationError::query()
                ->where('integration_id', $integration->id)
                ->orderByDesc('occurred_at')
                ->limit(10)
                ->get(['category', 'operation', 'message', 'occurred_at'])
                ->map(fn (IntegrationError $e) => [
                    'category' => $e->category,
                    'operation' => $e->operation,
                    'message' => $e->message,
                    'occurred_at' => $e->occurred_at->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /**
     * CONNECTED, SYNCING, ERROR or ACTION_REQUIRED (credentials or settings
     * need a person); PENDING before the first successful check.
     */
    public function status(TenantIntegration $integration): string
    {
        return match ($integration->status) {
            'connected' => 'CONNECTED',
            'syncing' => 'SYNCING',
            'action_required' => 'ACTION_REQUIRED',
            'error' => 'ERROR',
            default => strtoupper($integration->status),
        };
    }
}
