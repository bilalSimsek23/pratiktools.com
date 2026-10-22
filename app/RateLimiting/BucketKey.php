<?php

namespace App\RateLimiting;

final class BucketKey
{
    public static function tenant(string $tenantId, string $provider): string
    {
        return "tenant:{$tenantId}:{$provider}";
    }

    /**
     * A bucket shared by every tenant, for limits that providers apply per
     * platform (for example IYS limits per egress IP).
     */
    public static function global(string $provider, string $pool = 'default'): string
    {
        return "global:{$provider}:{$pool}";
    }
}
