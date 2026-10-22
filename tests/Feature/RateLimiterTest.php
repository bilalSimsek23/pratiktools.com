<?php

namespace Tests\Feature;

use App\RateLimiting\BucketKey;
use App\RateLimiting\ProviderRateLimiter;
use App\RateLimiting\TokenBucketLimiter;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('redis')]
final class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Redis::connection()->flushdb();
    }

    public function test_bucket_allows_capacity_then_blocks_with_retry_after(): void
    {
        $limiter = app(TokenBucketLimiter::class);
        $bucket = [BucketKey::tenant('t1', 'demo') => ['capacity' => 3, 'refill_per_second' => 1]];

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($limiter->attempt($bucket)->allowed);
        }

        $denied = $limiter->attempt($bucket);
        $this->assertFalse($denied->allowed);
        $this->assertGreaterThan(0, $denied->retryAfterMs);
        $this->assertLessThanOrEqual(1000, $denied->retryAfterMs);
    }

    public function test_tenant_buckets_are_independent(): void
    {
        config(['platform.rate_limits.providers.demo' => ['tenant' => ['capacity' => 1, 'refill_per_second' => 0.01]]]);
        $limiter = app(ProviderRateLimiter::class);

        $this->assertTrue($limiter->attempt('demo', 'tenant-a')->allowed);
        $this->assertFalse($limiter->attempt('demo', 'tenant-a')->allowed);
        $this->assertTrue($limiter->attempt('demo', 'tenant-b')->allowed);
    }

    public function test_platform_global_bucket_is_shared_and_consumed_atomically(): void
    {
        config(['platform.rate_limits.providers.iys' => [
            'tenant' => ['capacity' => 10, 'refill_per_second' => 0.01],
            'global' => ['capacity' => 2, 'refill_per_second' => 0.01],
        ]]);
        $limiter = app(ProviderRateLimiter::class);

        $this->assertTrue($limiter->attempt('iys', 'tenant-a')->allowed);
        $this->assertTrue($limiter->attempt('iys', 'tenant-b')->allowed);

        $denied = $limiter->attempt('iys', 'tenant-c');
        $this->assertFalse($denied->allowed);
        $this->assertSame(BucketKey::global('iys'), $denied->limitedBy);

        // A denied attempt must not have consumed tenant-c's own tokens.
        $tokens = (float) Redis::connection()->hget('ratelimit:'.BucketKey::tenant('tenant-c', 'iys'), 'tokens');
        $this->assertEqualsWithDelta(10.0, $tokens, 0.1);
    }

    public function test_unconfigured_provider_is_not_limited(): void
    {
        $this->assertTrue(app(ProviderRateLimiter::class)->attempt('nothing-configured', 'tenant-a')->allowed);
    }
}
