<?php

namespace App\Connectors\OneSignal;

use App\Integrations\Errors\ProviderError;
use App\RateLimiting\ProviderRateLimiter;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * HTTPS calls to api.onesignal.com: App API key auth ("Key …"), tenant rate
 * buckets, bounded retries honouring Retry-After (max(Retry-After,
 * backoff)), errors normalized to ProviderError (401/403 auth, 429 rate
 * limited, 5xx and timeouts retryable, other 4xx permanent). Logs carry the
 * operation, status and request id only.
 */
final class OneSignalTransport
{
    private int $apiCalls = 0;

    /**
     * @param  (Closure(int): void)|null  $sleep  milliseconds; replaced in tests
     */
    public function __construct(
        private readonly Http $http,
        private readonly OneSignalCredentials $credentials,
        private readonly ProviderRateLimiter $rateLimiter,
        private readonly ?string $tenantId,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 30,
        private readonly int $maxAttempts = 3,
        private readonly int $baseBackoffMs = 2000,
        private ?Closure $sleep = null,
    ) {
        $this->sleep ??= fn (int $ms) => usleep($ms * 1000);
    }

    public function credentials(): OneSignalCredentials
    {
        return $this->credentials;
    }

    public function apiCalls(): int
    {
        return $this->apiCalls;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     */
    public function request(string $operation, string $bucket, string $method, string $path, array $query = [], ?array $json = null): array
    {
        $url = rtrim((string) config('push.onesignal.base_url'), '/').$path;

        for ($attempt = 1; ; $attempt++) {
            $this->throttle($bucket, $operation);
            $requestId = (string) Str::uuid();
            $started = hrtime(true);

            try {
                $this->apiCalls++;
                $pending = $this->http->withHeaders([
                    'Authorization' => 'Key '.$this->credentials->apiKey->reveal(),
                    'X-Request-Id' => $requestId,
                ])->acceptJson()->connectTimeout($this->connectTimeout)->timeout($this->timeout);
                $response = $method === 'GET' ? $pending->get($url, $query) : $pending->withQueryParameters($query)->post($url, $json ?? []);
            } catch (ConnectionException $e) {
                $this->log($operation, $attempt, $started, 'connection', $requestId);
                $this->retryOrThrow(new ProviderError(ProviderError::RETRYABLE, 'OneSignal was unreachable or timed out.', $operation, previous: $e), $attempt);

                continue;
            }

            $this->log($operation, $attempt, $started, (string) $response->status(), $requestId);

            if ($response->successful()) {
                $body = $response->json();
                if (! is_array($body)) {
                    throw ProviderError::schema('OneSignal returned a non-JSON response.', $operation);
                }

                return $body;
            }

            $this->retryOrThrow($this->classify($response, $operation), $attempt);
        }
    }

    private function classify(Response $response, string $operation): ProviderError
    {
        $status = $response->status();

        return match (true) {
            $status === 401, $status === 403 => ProviderError::auth("OneSignal rejected the App API key or its permissions (HTTP {$status}).", $operation),
            $status === 429 => new ProviderError(ProviderError::RATE_LIMITED, 'OneSignal rate limit reached.', $operation, max(1, (int) $response->header('Retry-After')) * 1000),
            $status >= 500 => new ProviderError(ProviderError::RETRYABLE, "OneSignal server error (HTTP {$status}).", $operation, ((int) $response->header('Retry-After')) * 1000),
            default => new ProviderError(ProviderError::PERMANENT, "OneSignal rejected the request (HTTP {$status}).", $operation),
        };
    }

    private function retryOrThrow(ProviderError $error, int $attempt): void
    {
        if (! $error->isRetryable() || $attempt >= $this->maxAttempts) {
            throw $error;
        }

        $backoff = $this->baseBackoffMs * (2 ** ($attempt - 1));
        ($this->sleep)(max($error->retryAfterMs, $backoff) + random_int(0, intdiv($this->baseBackoffMs, 2)));
    }

    private function throttle(string $bucket, string $operation): void
    {
        for ($i = 0; ; $i++) {
            $decision = $this->rateLimiter->attempt($bucket, $this->tenantId);
            if ($decision->allowed) {
                return;
            }
            if ($i >= 30) {
                throw new ProviderError(ProviderError::RATE_LIMITED, "Local OneSignal rate budget [{$bucket}] exhausted.", $operation, $decision->retryAfterMs);
            }
            ($this->sleep)(max($decision->retryAfterMs, 50));
        }
    }

    private function log(string $operation, int $attempt, int $started, string $outcome, string $requestId): void
    {
        Log::debug('onesignal.call', [
            'provider' => 'onesignal',
            'operation' => $operation,
            'attempt' => $attempt,
            'outcome' => $outcome,
            'request_id' => $requestId,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
        ]);
    }
}
