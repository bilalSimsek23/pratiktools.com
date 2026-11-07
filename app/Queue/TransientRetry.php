<?php

namespace App\Queue;

use App\Integrations\Errors\ProviderError;
use App\Models\IntegrationSyncRun;

/**
 * Bounded retry of a queued sync run that was deferred on a transient
 * error (SyncTracker::defer): the job re-queues itself after the next delay
 * of commerce.sync.retry_backoff_seconds, and gives up after the last one.
 */
final class TransientRetry
{
    public static function deferred(IntegrationSyncRun $run): bool
    {
        return $run->status === 'running' && $run->error_category === ProviderError::RETRYABLE;
    }

    /**
     * Seconds to wait before attempt $retry + 1, or null when the retries
     * are used up.
     */
    public static function delay(int $retry): ?int
    {
        $backoff = array_values((array) config('commerce.sync.retry_backoff_seconds'));

        return isset($backoff[$retry]) ? (int) $backoff[$retry] : null;
    }

    public static function exhausted(IntegrationSyncRun $run, int $retries): ProviderError
    {
        return new ProviderError(ProviderError::RETRYABLE, "Gave up after {$retries} retries: ".$run->error_message);
    }
}
