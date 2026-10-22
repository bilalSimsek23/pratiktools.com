<?php

namespace App\RateLimiting;

use Illuminate\Contracts\Redis\Factory as Redis;
use Predis\ClientInterface;

/**
 * Redis token buckets. Several buckets are checked and consumed in one
 * atomic Lua call, so a request either takes a token from every bucket
 * (tenant bucket and platform-wide bucket) or from none.
 */
final class TokenBucketLimiter
{
    private const PREFIX = 'ratelimit:';

    private const SCRIPT = <<<'LUA'
        local now = tonumber(ARGV[1])
        local cost = tonumber(ARGV[2])
        local n = #KEYS
        local states = {}
        local wait = 0
        local blocker = 0
        for i = 1, n do
            local capacity = tonumber(ARGV[1 + i * 2])
            local refill = tonumber(ARGV[2 + i * 2])
            local data = redis.call('HMGET', KEYS[i], 'tokens', 'ts')
            local tokens = tonumber(data[1])
            local ts = tonumber(data[2])
            if tokens == nil then tokens = capacity; ts = now end
            tokens = math.min(capacity, tokens + math.max(0, now - ts) * refill / 1000)
            states[i] = {tokens, capacity, refill}
            if tokens < cost then
                local w = math.ceil((cost - tokens) * 1000 / refill)
                if w > wait then wait = w; blocker = i end
            end
        end
        local allowed = (blocker == 0) and 1 or 0
        for i = 1, n do
            local tokens = states[i][1]
            if allowed == 1 then tokens = tokens - cost end
            redis.call('HSET', KEYS[i], 'tokens', tostring(tokens), 'ts', tostring(now))
            redis.call('PEXPIRE', KEYS[i], math.ceil(states[i][2] * 1000 / states[i][3]) + 60000)
        end
        return {allowed, wait, blocker}
    LUA;

    public function __construct(private readonly Redis $redis) {}

    /**
     * @param  array<string, array{capacity: int|float, refill_per_second: int|float}>  $buckets  key => limits
     */
    public function attempt(array $buckets, int $cost = 1): RateLimitDecision
    {
        if ($buckets === []) {
            return new RateLimitDecision(true);
        }

        $keys = [];
        $args = [(int) floor(microtime(true) * 1000), $cost];

        foreach ($buckets as $key => $limits) {
            $keys[] = self::PREFIX.$key;
            $args[] = $limits['capacity'];
            $args[] = $limits['refill_per_second'];
        }

        $result = $this->evaluate($keys, $args);

        $names = array_keys($buckets);

        return new RateLimitDecision(
            allowed: (int) $result[0] === 1,
            retryAfterMs: (int) $result[1],
            limitedBy: (int) $result[2] > 0 ? $names[(int) $result[2] - 1] : null,
        );
    }

    /**
     * EVAL has a different signature in predis (script, numkeys, ...keys/args)
     * and phpredis (script, [keys..., args...], numkeys).
     *
     * @param  list<string>  $keys
     * @param  list<int|float>  $args
     * @return array{0: int|string, 1: int|string, 2: int|string}
     */
    private function evaluate(array $keys, array $args): array
    {
        $connection = $this->redis->connection();

        if ($connection->client() instanceof ClientInterface) {
            return $connection->command('eval', [self::SCRIPT, count($keys), ...$keys, ...$args]);
        }

        return $connection->command('eval', [self::SCRIPT, [...$keys, ...$args], count($keys)]);
    }
}
