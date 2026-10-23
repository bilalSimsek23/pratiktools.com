<?php

namespace App\Connectors\Iys;

use App\Integrations\Errors\ProviderError;
use App\RateLimiting\ProviderRateLimiter;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * İYS REST client in read-only (shadow) mode.
 *
 * Only the operations in READ_OPERATIONS exist. Several İYS reads are POST
 * requests, so the guard is an operation allowlist, not an HTTP method
 * check. Anything else raises ReadOnlyModeViolation before a request is
 * built. Logs carry the operation, status and İYS error code only, never
 * addresses or tokens.
 */
final class IysClient
{
    /** name => [method, path template] */
    public const READ_OPERATIONS = [
        'brands' => ['GET', '/sps/{iysCode}/brands'],
        'subscription' => ['GET', '/sps/{iysCode}/subscription'],
        'consent_status' => ['POST', '/sps/{iysCode}/brands/{brandCode}/consents/status'],
        'consent_status_bulk' => ['POST', '/sps/{iysCode}/brands/{brandCode}/consents/{recipientType}/status/{type}'],
        'consent_changes' => ['GET', '/sps/{iysCode}/brands/{brandCode}/consents/changes'],
        'consent_history' => ['POST', '/sps/{iysCode}/brands/{brandCode}/consents/history'],
        'daily_summary' => ['GET', '/sps/{iysCode}/brands/{brandCode}/changes/{reportDate}/recipients/{recipientType}'],
        'daily_file' => ['GET', '/sps/{iysCode}/brands/{brandCode}/changes/{reportDate}/file'],
        'agreement_info' => ['GET', '/sps/{iysCode}/brands/{brandCode}/agreements/{reportDate}'],
        'push_status' => ['GET', '/sps/{iysCode}/brands/{brandCode}/push/registration'],
    ];

    /**
     * Known state-changing operations, named so a violation is explicit.
     */
    public const WRITE_OPERATIONS = [
        'consent_add', 'consent_add_v2', 'consent_bulk_add', 'consent_bulk_add_v2', 'agreement_save',
        'push_register', 'push_unregister', 'retailer_add', 'retailer_delete', 'retailer_access_add',
        'retailer_access_grant', 'retailer_access_remove', 'retailer_access_remove_all', 'via_start', 'via_confirm',
    ];

    private int $apiCalls = 0;

    /**
     * @param  (Closure(int): void)|null  $sleep  milliseconds; replaced in tests
     */
    public function __construct(
        private readonly Http $http,
        private readonly IysCredentials $credentials,
        private readonly IysTokenManager $tokens,
        private readonly ProviderRateLimiter $rateLimiter,
        private readonly ?string $tenantId,
        private readonly int $timeout = 30,
        private readonly int $maxAttempts = 4,
        private readonly int $baseBackoffMs = 500,
        private ?Closure $sleep = null,
    ) {
        $this->sleep ??= fn (int $ms) => usleep($ms * 1000);
    }

    public function apiCalls(): int
    {
        return $this->apiCalls;
    }

    public function credentials(): IysCredentials
    {
        return $this->credentials;
    }

    /**
     * @param  array<string, string|int>  $path  extra path parameters
     * @param  array<string, string|int>  $query
     * @param  array<string, mixed>|null  $json
     */
    public function call(string $operation, array $path = [], array $query = [], ?array $json = null): Response
    {
        if (! isset(self::READ_OPERATIONS[$operation])) {
            throw new ReadOnlyModeViolation(in_array($operation, self::WRITE_OPERATIONS, true)
                ? "İYS operation [{$operation}] changes consent state and is disabled: PratikTools is a read-only shadow, the current integrator is the single writer."
                : "İYS operation [{$operation}] is not on the read-only allowlist.");
        }

        [$method, $template] = self::READ_OPERATIONS[$operation];
        $url = rtrim($this->credentials->baseUrl, '/').$this->path($template, $path);
        $rejected = null;

        for ($attempt = 1; ; $attempt++) {
            $this->throttle($operation);
            $token = $this->tokens->accessToken($this->credentials, $rejected);
            $requestId = (string) Str::uuid();
            $started = hrtime(true);

            try {
                $this->apiCalls++;
                $request = $this->http->withToken($token)->acceptJson()->timeout($this->timeout)->withHeaders(['X-Request-Id' => $requestId]);
                $response = $method === 'GET'
                    ? $request->get($url, $query)
                    : $request->withQueryParameters($query)->post($url, $json ?? []);
            } catch (ConnectionException $e) {
                $error = new ProviderError(ProviderError::RETRYABLE, 'İYS was unreachable or timed out.', $operation, previous: $e);
                $this->log($operation, $attempt, $started, 'connection', null, $requestId);
                $this->retryOrThrow($error, $attempt);

                continue;
            }

            $this->log($operation, $attempt, $started, (string) $response->status(), $this->errorCode($response), $requestId);

            if ($response->successful()) {
                return $response;
            }

            if ($response->status() === 401 && $rejected === null) {
                // Expired or revoked token: replace exactly this one, once.
                $rejected = $token;

                continue;
            }

            $this->retryOrThrow($this->classify($response, $operation), $attempt);
        }
    }

    /**
     * The token endpoint call used by IysTokenManager. Credentials go in
     * the form body only, never in logs.
     *
     * @return Closure(array<string, string>): array<string, mixed>
     */
    public static function tokenRequester(Http $http, string $baseUrl, int $timeout): Closure
    {
        return function (array $fields) use ($http, $baseUrl, $timeout): array {
            try {
                $response = $http->asForm()->acceptJson()->timeout($timeout)->post(rtrim($baseUrl, '/').'/oauth2/token', $fields);
            } catch (ConnectionException $e) {
                throw new ProviderError(ProviderError::RETRYABLE, 'İYS token endpoint was unreachable.', 'token', previous: $e);
            }

            if ($response->status() === 400 || $response->status() === 401) {
                throw ProviderError::auth('İYS rejected the API credentials.', 'token');
            }
            if ($response->status() === 429) {
                throw new ProviderError(ProviderError::RATE_LIMITED, 'İYS token endpoint rate limit reached.', 'token');
            }
            if (! $response->successful()) {
                throw new ProviderError($response->serverError() ? ProviderError::RETRYABLE : ProviderError::PERMANENT, "İYS token request failed (HTTP {$response->status()}).", 'token');
            }

            return (array) $response->json();
        };
    }

    /**
     * @param  array<string, string|int>  $path
     */
    private function path(string $template, array $path): string
    {
        $values = ['iysCode' => $this->credentials->iysCode, 'brandCode' => $this->credentials->brandCode] + $path;

        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($values) {
            if (! isset($values[$m[1]])) {
                throw new \InvalidArgumentException("Missing İYS path parameter [{$m[1]}].");
            }

            return rawurlencode((string) $values[$m[1]]);
        }, $template) ?? $template;
    }

    private function throttle(string $operation): void
    {
        $buckets = $operation === 'consent_status' ? ['iys', 'iys_status'] : ['iys'];

        foreach ($buckets as $bucket) {
            for ($i = 0; ; $i++) {
                $decision = $this->rateLimiter->attempt($bucket, $this->tenantId);
                if ($decision->allowed) {
                    break;
                }
                if ($i >= 20 || $decision->retryAfterMs > 60_000) {
                    throw new ProviderError(ProviderError::RATE_LIMITED, "Local İYS rate budget [{$bucket}] exhausted.", $operation, $decision->retryAfterMs);
                }
                ($this->sleep)(max($decision->retryAfterMs, 50));
            }
        }
    }

    private function classify(Response $response, string $operation): ProviderError
    {
        $code = $this->errorCode($response);
        $suffix = $code !== null ? " ({$code})" : '';

        return match (true) {
            $response->status() === 429 => new ProviderError(ProviderError::RATE_LIMITED, 'İYS rate limit reached'.$suffix.'.', $operation, ((int) $response->header('Retry-After')) * 1000),
            $response->status() === 401 => ProviderError::auth('İYS rejected the access token'.$suffix.'.', $operation),
            // Code/brand mismatch, closed subscription, package without API.
            $response->status() === 403 => ProviderError::auth('İYS refused access: account, brand or subscription'.$suffix.'.', $operation),
            $response->serverError() => new ProviderError(ProviderError::RETRYABLE, 'İYS server error'.$suffix.'.', $operation),
            default => new ProviderError(ProviderError::PERMANENT, "İYS rejected the request (HTTP {$response->status()}){$suffix}.", $operation),
        };
    }

    private function retryOrThrow(ProviderError $error, int $attempt): void
    {
        if (! $error->isRetryable() || $attempt >= $this->maxAttempts) {
            throw $error;
        }

        ($this->sleep)(max($error->retryAfterMs, $this->baseBackoffMs * (2 ** ($attempt - 1)) + random_int(0, intdiv($this->baseBackoffMs, 2))));
    }

    private function errorCode(Response $response): ?string
    {
        if ($response->successful()) {
            return null;
        }
        $errors = $response->json('errors');
        $first = is_array($errors) ? ($errors[0] ?? $errors) : null;
        $code = is_array($first) ? ($first['code'] ?? $first['code:'] ?? null) : null;

        return is_scalar($code) && preg_match('/^H\d{3}$/', (string) $code) ? (string) $code : null;
    }

    private function log(string $operation, int $attempt, int $started, string $outcome, ?string $code, string $requestId): void
    {
        Log::debug('iys.call', [
            'provider' => 'iys',
            'operation' => $operation,
            'attempt' => $attempt,
            'outcome' => $outcome,
            'iys_code' => $code,
            'request_id' => $requestId,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
        ]);
    }
}
