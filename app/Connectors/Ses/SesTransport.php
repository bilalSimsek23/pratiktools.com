<?php

namespace App\Connectors\Ses;

use App\Integrations\Errors\ProviderError;
use App\RateLimiting\BucketKey;
use App\RateLimiting\ProviderRateLimiter;
use App\RateLimiting\TokenBucketLimiter;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * HTTPS calls to the SES API v2 (email.{region}.amazonaws.com), SigV4
 * signed. Reads use a small fixed budget; sends use a per-tenant bucket
 * (tenant:{id}:amazon_ses) sized from the account's MaxSendRate.
 *
 * Errors become ProviderError: auth (401/403, signature), quota exhausted
 * ("Daily message quota exceeded", not retried), rate limited (429 /
 * throttling, retried with backoff and jitter), retryable (5xx), permanent
 * (other 4xx). Only error codes are kept, never AWS messages: those can
 * contain addresses.
 *
 * SES has no idempotency token. A send is retried only when it certainly
 * did not reach SES (DNS failure, connection refused, connect timeout) or
 * SES said so (throttling, 5xx, as the AWS SDKs do); a read timeout after
 * the request was sent is AMBIGUOUS and never retried automatically.
 */
final class SesTransport
{
    private int $apiCalls = 0;

    /**
     * @param  (Closure(int): void)|null  $sleep  milliseconds; replaced in tests
     */
    public function __construct(
        private readonly Http $http,
        private readonly SesCredentials $credentials,
        private readonly ProviderRateLimiter $rateLimiter,
        private readonly TokenBucketLimiter $buckets,
        private readonly string $tenantId,
        private readonly float $sendRate,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 20,
        private readonly int $maxAttempts = 3,
        private readonly int $baseBackoffMs = 1000,
        private ?Closure $sleep = null,
    ) {
        $this->sleep ??= fn (int $ms) => usleep($ms * 1000);
    }

    public function credentials(): SesCredentials
    {
        return $this->credentials;
    }

    public function apiCalls(): int
    {
        return $this->apiCalls;
    }

    public function host(): string
    {
        return "email.{$this->credentials->region}.amazonaws.com";
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     */
    public function request(string $operation, string $method, string $path, array $query = [], ?array $json = null, bool $isSend = false): array
    {
        $body = $json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $queryString = SigV4Signer::canonicalQuery($query);
        $url = 'https://'.$this->host().$path.($queryString === '' ? '' : '?'.$queryString);

        for ($attempt = 1; ; $attempt++) {
            $this->throttle($operation, $isSend);
            $started = hrtime(true);
            $headers = SigV4Signer::sign($method, $this->host(), $path, $query, $body, $this->credentials->region, 'ses',
                $this->credentials->accessKeyId->reveal(), $this->credentials->secretAccessKey->reveal());

            try {
                $this->apiCalls++;
                $pending = $this->http->withHeaders($headers)->acceptJson()->connectTimeout($this->connectTimeout)->timeout($this->timeout);
                if ($body !== '') {
                    $pending = $pending->withBody($body, 'application/json');
                }
                $response = $pending->send($method, $url);
            } catch (ConnectionException $e) {
                $safe = self::neverReachedServer($e);
                $this->log($operation, $attempt, $started, $safe ? 'connect_failed' : 'timeout', null);
                if (! $safe && $isSend) {
                    throw new ProviderError(ProviderError::AMBIGUOUS, 'SES did not answer after the request was sent; it may have been accepted.', $operation, previous: $e);
                }
                $this->retryOrThrow(new ProviderError(ProviderError::RETRYABLE, 'SES was unreachable or timed out.', $operation, previous: $e), $attempt);

                continue;
            }

            $this->log($operation, $attempt, $started, (string) $response->status(), $response->header('x-amzn-RequestId') ?: null);

            if ($response->successful()) {
                $decoded = $response->body() === '' ? [] : $response->json();
                if (! is_array($decoded)) {
                    throw ProviderError::schema('SES returned a non-JSON response.', $operation);
                }

                return $decoded;
            }

            $this->retryOrThrow($this->classify($response, $operation), $attempt);
        }
    }

    /**
     * AWS error code from x-amzn-ErrorType ("Code:url") or the body.
     */
    public static function errorCode(Response $response): string
    {
        $header = (string) $response->header('x-amzn-ErrorType');
        $code = $header !== '' ? explode(':', $header)[0] : (string) ($response->json('__type') ?? $response->json('code') ?? $response->json('Code') ?? '');
        $code = str_contains($code, '#') ? substr($code, strrpos($code, '#') + 1) : $code;

        return preg_replace('/[^A-Za-z0-9]/', '', $code) ?: 'Unknown';
    }

    private function classify(Response $response, string $operation): ProviderError
    {
        $status = $response->status();
        $code = self::errorCode($response);
        $message = strtolower((string) ($response->json('message') ?? $response->json('Message') ?? ''));

        return match (true) {
            str_contains($message, 'daily message quota exceeded') => new ProviderError(ProviderError::QUOTA_EXHAUSTED, "SES daily sending quota exceeded ({$code}).", $operation, 600_000),
            $status === 429, in_array($code, ['TooManyRequestsException', 'ThrottlingException', 'Throttling'], true) => new ProviderError(ProviderError::RATE_LIMITED, "SES throttled the request ({$code}).", $operation, max(1, (int) $response->header('Retry-After')) * 1000),
            $status === 401, $status === 403, in_array($code, ['UnrecognizedClientException', 'InvalidSignatureException', 'AccessDeniedException', 'SignatureDoesNotMatch', 'InvalidClientTokenId'], true) => ProviderError::auth("SES rejected the credentials or their permissions ({$code}).", $operation),
            $status >= 500 => new ProviderError(ProviderError::RETRYABLE, "SES server error (HTTP {$status}, {$code}).", $operation),
            default => new ProviderError(ProviderError::PERMANENT, "SES rejected the request ({$code}).", $operation),
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

    private function throttle(string $operation, bool $isSend): void
    {
        for ($i = 0; ; $i++) {
            $decision = $isSend
                ? $this->buckets->attempt([BucketKey::tenant($this->tenantId, 'amazon_ses') => ['capacity' => max(1, (int) floor($this->sendRate)), 'refill_per_second' => $this->sendRate]])
                : $this->rateLimiter->attempt('amazon_ses_read', $this->tenantId);
            if ($decision->allowed) {
                return;
            }
            if ($i >= 30) {
                throw new ProviderError(ProviderError::RATE_LIMITED, 'Local SES rate budget exhausted.', $operation, $decision->retryAfterMs);
            }
            ($this->sleep)(max($decision->retryAfterMs, 50));
        }
    }

    /**
     * cURL failures that happen before the request is written: DNS,
     * refused connection, connect timeout, TLS handshake.
     */
    private static function neverReachedServer(ConnectionException $e): bool
    {
        $m = $e->getMessage();

        return preg_match('/cURL error (6|7|35):/', $m) === 1 || str_contains($m, 'Connection timed out after') || str_contains($m, 'Could not resolve host') || str_contains($m, 'Failed to connect');
    }

    private function log(string $operation, int $attempt, int $started, string $outcome, ?string $requestId): void
    {
        Log::debug('ses.call', [
            'provider' => 'amazon_ses',
            'operation' => $operation,
            'attempt' => $attempt,
            'outcome' => $outcome,
            'aws_request_id' => $requestId,
            'region' => $this->credentials->region,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
        ]);
    }
}
