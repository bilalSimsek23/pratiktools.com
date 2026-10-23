<?php

namespace App\Connectors\Iys;

use App\Integrations\Errors\ProviderError;
use App\RateLimiting\ProviderRateLimiter;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\Encrypter;

/**
 * İYS OAuth2 tokens (access 2 h, refresh up to the returned
 * refresh_expires_in). Tokens live only in the cache, encrypted; never in
 * the database. The token endpoint accepts 10 requests per hour per IP,
 * so:
 * - one lock per credential set: concurrent workers wait for the one that
 *   refreshes instead of each requesting a token;
 * - refresh_token is preferred over a password grant;
 * - a platform-wide bucket (10/h) refuses to exceed the limit.
 */
final class IysTokenManager
{
    private const SKEW_SECONDS = 120;

    /**
     * @param  Closure(array<string, string>): array<string, mixed>  $requestToken  form fields => token response
     */
    public function __construct(
        private readonly Repository $cache,
        private readonly LockProvider $locks,
        private readonly Encrypter $encrypter,
        private readonly ProviderRateLimiter $rateLimiter,
        private readonly Closure $requestToken,
    ) {}

    /**
     * A valid access token. $rejected is the token the API just refused
     * (401): only that exact token is replaced, so a burst of 401s causes
     * one refresh, not one per worker.
     */
    public function accessToken(IysCredentials $credentials, ?string $rejected = null): string
    {
        $state = $this->read($credentials);
        if ($this->usable($state, $rejected)) {
            return $state['access_token'];
        }

        return $this->locks->lock($credentials->tokenKey().':lock', 30)->block(20, function () use ($credentials, $rejected) {
            $state = $this->read($credentials);
            if ($this->usable($state, $rejected)) {
                return $state['access_token'];
            }

            $response = null;
            if ($state !== null && $state['refresh_expires_at'] > time() + self::SKEW_SECONDS) {
                try {
                    $response = $this->request(['grant_type' => 'refresh_token', 'refresh_token' => $state['refresh_token']]);
                } catch (ProviderError $e) {
                    if ($e->category !== ProviderError::AUTH) {
                        throw $e;
                    }
                }
            }

            $response ??= $this->request([
                'grant_type' => 'password',
                'username' => $credentials->username->reveal(),
                'password' => $credentials->password->reveal(),
            ]);

            return $this->store($credentials, $response);
        });
    }

    public function forget(IysCredentials $credentials): void
    {
        $this->cache->forget($credentials->tokenKey());
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    private function request(array $fields): array
    {
        $decision = $this->rateLimiter->attempt('iys_token', null);
        if (! $decision->allowed) {
            throw new ProviderError(ProviderError::RATE_LIMITED, 'İYS token budget (10 per hour) exhausted; waiting.', 'token', $decision->retryAfterMs);
        }

        return ($this->requestToken)($fields);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function store(IysCredentials $credentials, array $response): string
    {
        if (! is_string($response['access_token'] ?? null) || ! is_string($response['refresh_token'] ?? null)) {
            throw ProviderError::schema('İYS token response is missing tokens.', 'token');
        }

        $now = time();
        $state = [
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'],
            'access_expires_at' => $now + (int) ($response['expires_in'] ?? 7200),
            'refresh_expires_at' => $now + (int) ($response['refresh_expires_in'] ?? 14400),
        ];

        $this->cache->put($credentials->tokenKey(), $this->encrypter->encrypt($state), $state['refresh_expires_at'] - $now);

        return $state['access_token'];
    }

    /**
     * @return array{access_token: string, refresh_token: string, access_expires_at: int, refresh_expires_at: int}|null
     */
    private function read(IysCredentials $credentials): ?array
    {
        $payload = $this->cache->get($credentials->tokenKey());

        try {
            return is_string($payload) ? $this->encrypter->decrypt($payload) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array{access_token: string, access_expires_at: int}|null  $state
     */
    private function usable(?array $state, ?string $rejected): bool
    {
        return $state !== null
            && $state['access_expires_at'] > time() + self::SKEW_SECONDS
            && ($rejected === null || ! hash_equals($state['access_token'], $rejected));
    }
}
