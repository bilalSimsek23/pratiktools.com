<?php

namespace App\RateLimiting;

/**
 * Entry point for connectors: "may tenant X call provider Y now?".
 * Limits come from config('platform.rate_limits.providers'); a provider may
 * declare a per-tenant bucket, a platform-wide bucket, or both.
 */
final class ProviderRateLimiter
{
    public function __construct(private readonly TokenBucketLimiter $limiter) {}

    public function attempt(string $provider, ?string $tenantId, int $cost = 1, string $pool = 'default'): RateLimitDecision
    {
        $config = config("platform.rate_limits.providers.{$provider}", []);
        $buckets = [];

        if ($tenantId !== null && isset($config['tenant'])) {
            $buckets[BucketKey::tenant($tenantId, $provider)] = $config['tenant'];
        }

        if (isset($config['global'])) {
            $buckets[BucketKey::global($provider, $pool)] = $config['global'];
        }

        return $this->limiter->attempt($buckets, $cost);
    }
}
