<?php

namespace App\Integrations\Errors;

use Illuminate\Contracts\Database\LostConnectionDetector;
use Predis\CommunicationException;
use RedisException;
use Throwable;

/**
 * Failures that say nothing about the data or the credentials and usually
 * pass by themselves: a dropped Redis or database connection, a provider
 * that timed out or rate limited us. A queued sync defers these to a later
 * attempt (resuming from its saved page) instead of failing the run.
 */
final class TransientFailure
{
    public static function is(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ProviderError) {
                return $cause->isRetryable();
            }
            if ($cause instanceof CommunicationException || $cause instanceof RedisException
                || app(LostConnectionDetector::class)->causedByLostConnection($cause)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Safe to store and show: the class only, never the exception message
     * (database errors carry bindings).
     */
    public static function reason(Throwable $e): string
    {
        if ($e instanceof ProviderError) {
            return $e->getMessage();
        }

        return 'Temporary connection problem ('.class_basename($e).'); retrying.';
    }
}
