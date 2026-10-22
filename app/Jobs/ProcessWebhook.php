<?php

namespace App\Jobs;

use App\Integrations\ProviderRegistry;
use App\Models\WebhookInboxItem;
use App\Queue\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Processes one webhook inbox item. The row is locked and its status
 * checked, so a duplicate dispatch or a concurrent worker never processes
 * the same item twice.
 */
final class ProcessWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(string $tenantId, public readonly string $inboxId)
    {
        $this->forTenant($tenantId);
        $this->onQueue('webhooks');
    }

    public function handle(ProviderRegistry $providers): void
    {
        $item = DB::transaction(function () {
            $item = WebhookInboxItem::query()->whereKey($this->inboxId)->lockForUpdate()->first();

            if ($item === null || in_array($item->processing_status, ['processed', 'ignored', 'processing'], true)) {
                return null;
            }

            $item->forceFill(['processing_status' => 'processing', 'attempts' => $item->attempts + 1])->save();

            return $item;
        });

        if ($item === null) {
            return;
        }

        $handler = $providers->webhookHandler($item->provider);

        try {
            $handler?->handle($item);

            $item->forceFill([
                'processing_status' => $handler === null ? 'ignored' : 'processed',
                'processed_at' => now(),
                'last_error' => null,
            ])->save();
        } catch (Throwable $e) {
            $item->forceFill([
                'processing_status' => 'failed',
                'last_error' => mb_substr($e::class, 0, 500),
            ])->save();

            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        // Final failure: the row stays "failed" for inspection; nothing to undo.
    }
}
