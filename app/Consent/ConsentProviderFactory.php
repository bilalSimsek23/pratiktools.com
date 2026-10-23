<?php

namespace App\Consent;

use App\Audit\Actor;
use App\Connectors\Iys\IysClient;
use App\Connectors\Iys\IysConsentConnector;
use App\Connectors\Iys\IysCredentials;
use App\Connectors\Iys\IysTokenManager;
use App\Consent\Contracts\ConsentProvider;
use App\Integrations\Errors\ProviderError;
use App\Models\TenantIntegration;
use App\RateLimiting\ProviderRateLimiter;
use App\Security\SensitiveValue;
use App\Security\Vault\SecretVault;
use App\Tenancy\Exceptions\TenantMismatch;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Client\Factory as Http;
use InvalidArgumentException;

/**
 * Builds the read-only ConsentProvider of a tenant's consent integration.
 *
 * Settings: iys_code, brand_code, environment (sandbox|production).
 * Secret (vault): JSON {"username": ..., "password": ...}.
 *
 * Production İYS only accepts calls from IP addresses registered for the
 * API user, so production is refused unless static egress IPs are
 * configured and production access is explicitly enabled.
 */
class ConsentProviderFactory
{
    public function __construct(
        private readonly SecretVault $vault,
        private readonly TenantContext $context,
        private readonly ProviderRateLimiter $rateLimiter,
        private readonly Http $http,
        private readonly Repository $cache,
        private readonly Encrypter $encrypter,
    ) {}

    public function for(TenantIntegration $integration, string $purpose, ?Actor $actor = null): ConsentProvider
    {
        if ($integration->tenant_id !== $this->context->requireTenant()) {
            throw new TenantMismatch;
        }
        if ($integration->provider !== 'iys' || $integration->capability !== 'consent') {
            throw new InvalidArgumentException('Integration is not an İYS consent integration.');
        }

        $settings = $integration->settings;
        $environment = (string) ($settings['environment'] ?? 'sandbox');
        $baseUrl = match ($environment) {
            'sandbox' => (string) config('consent.iys.sandbox_url'),
            'production' => $this->productionUrl(),
            default => throw new ProviderError(ProviderError::PERMANENT, 'Unknown İYS environment.'),
        };

        $iysCode = (int) ($settings['iys_code'] ?? 0);
        $brandCode = (int) ($settings['brand_code'] ?? 0);
        if ($iysCode <= 0 || $brandCode <= 0) {
            throw new ProviderError(ProviderError::PERMANENT, 'İYS integration needs iys_code and brand_code settings.');
        }

        $secret = json_decode($this->vault->reveal($integration, $purpose, $actor)->reveal(), true);
        if (! is_array($secret) || ! is_string($secret['username'] ?? null) || ! is_string($secret['password'] ?? null)) {
            throw ProviderError::auth('İYS secret must contain username and password.');
        }

        $credentials = new IysCredentials(
            $integration->id,
            new SensitiveValue($secret['username']),
            new SensitiveValue($secret['password']),
            $iysCode,
            $brandCode,
            $baseUrl,
            $environment,
        );
        unset($secret);

        $timeout = (int) config('consent.iys.timeout');
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            throw new \LogicException('İYS token refresh needs a cache store with locks (Redis).');
        }
        $tokens = new IysTokenManager($this->cache, $store, $this->encrypter, $this->rateLimiter, IysClient::tokenRequester($this->http, $baseUrl, $timeout));
        $client = new IysClient($this->http, $credentials, $tokens, $this->rateLimiter, $integration->tenant_id, $timeout, (int) config('consent.iys.max_attempts'), (int) config('consent.iys.base_backoff_ms', 500));

        return new IysConsentConnector($client);
    }

    private function productionUrl(): string
    {
        if (! config('consent.iys.allow_production') || config('platform.egress_static_ips') === []) {
            throw new ProviderError(ProviderError::PERMANENT, 'İYS production requires registered static egress IPs (EGRESS_STATIC_IPS) and IYS_ALLOW_PRODUCTION=true.');
        }

        return (string) config('consent.iys.production_url');
    }
}
