<?php

namespace App\Connectors\Ticimax;

use App\Connectors\Ticimax\Soap\SoapTransport;
use App\Integrations\Errors\ProviderError;
use App\RateLimiting\ProviderRateLimiter;
use Closure;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Tenant-scoped Ticimax SOAP client: read-only operation allowlist,
 * authorization code injection, tenant rate limiting, retry with
 * exponential backoff and jitter, and normalized errors. Logs operation
 * metadata only, never parameters or response bodies.
 */
final class TicimaxClient
{
    /**
     * The only operations this client may call. Every one is a read.
     * Write operations (UpdateUyeIzin, HediyeCekiOlustur, Save*, Set*, ...)
     * are rejected before any network call.
     */
    public const READ_OPERATIONS = [
        'UyeServis' => ['SelectUyeler'],
        'CustomServis' => ['SelectMailList'],
        'SiparisServis' => ['SelectSiparis', 'SelectSepet', 'SelectSiparisDurumlari'],
        'UrunServis' => ['SelectUrun', 'SelectUrunCount', 'SelectKategori'],
    ];

    private int $apiCalls = 0;

    /**
     * @param  Closure(int): void|null  $sleep  milliseconds; replaced in tests
     */
    public function __construct(
        private readonly SoapTransport $transport,
        private readonly TicimaxCredentials $credentials,
        private readonly ProviderRateLimiter $rateLimiter,
        private readonly ?string $tenantId,
        private readonly int $maxAttempts = 3,
        private readonly int $baseBackoffMs = 500,
        private ?Closure $sleep = null,
    ) {
        $this->sleep ??= fn (int $ms) => usleep($ms * 1000);
    }

    public function credentials(): TicimaxCredentials
    {
        return $this->credentials;
    }

    public function apiCalls(): int
    {
        return $this->apiCalls;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function call(string $service, string $operation, array $parameters = []): array
    {
        if (! in_array($operation, self::READ_OPERATIONS[$service] ?? [], true)) {
            throw new LogicException("Ticimax operation {$service}::{$operation} is not on the read-only allowlist.");
        }

        $attempt = 0;

        while (true) {
            $attempt++;
            $this->throttle();
            $started = hrtime(true);

            try {
                $this->apiCalls++;
                $result = $this->transport->call($service, $operation, ['UyeKodu' => $this->credentials->memberCode->reveal()] + $parameters);
                $this->assertNoResultError($result, $operation);
                $this->log($operation, $attempt, $started, 'ok');

                return $result;
            } catch (Throwable $e) {
                $error = TicimaxErrorClassifier::classify($e, $operation);
                $this->log($operation, $attempt, $started, $error->category);

                if (! $error->isRetryable() || $attempt >= $this->maxAttempts) {
                    throw $error;
                }

                ($this->sleep)($this->backoffMs($attempt, $error));
            }
        }
    }

    private function throttle(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $decision = $this->rateLimiter->attempt('ticimax', $this->tenantId);
            if ($decision->allowed) {
                return;
            }
            ($this->sleep)(min(max($decision->retryAfterMs, 50), 2000));
        }

        throw new ProviderError(ProviderError::RATE_LIMITED, 'Local Ticimax rate limit budget exhausted.', null, 1000);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function assertNoResultError(array $result, string $operation): void
    {
        $root = $result[$operation.'Result'] ?? null;

        if (is_array($root) && ($root['IsError'] ?? false) === true) {
            throw TicimaxErrorClassifier::fromResultMessage($root['ErrorMessage'] ?? null, $operation);
        }
    }

    private function backoffMs(int $attempt, ProviderError $error): int
    {
        $exponential = $this->baseBackoffMs * (2 ** ($attempt - 1));

        return max($error->retryAfterMs, $exponential + random_int(0, $this->baseBackoffMs));
    }

    private function log(string $operation, int $attempt, int $started, string $outcome): void
    {
        Log::debug('ticimax.call', [
            'provider' => 'ticimax',
            'operation' => $operation,
            'attempt' => $attempt,
            'outcome' => $outcome,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
        ]);
    }
}
