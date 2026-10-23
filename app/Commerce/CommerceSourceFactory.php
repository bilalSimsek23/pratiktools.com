<?php

namespace App\Commerce;

use App\Audit\Actor;
use App\Commerce\Contracts\CommerceSource;
use App\Connectors\Ticimax\Soap\PhpSoapTransport;
use App\Connectors\Ticimax\Soap\SoapTransport;
use App\Connectors\Ticimax\TicimaxClient;
use App\Connectors\Ticimax\TicimaxCommerceConnector;
use App\Connectors\Ticimax\TicimaxCredentials;
use App\Connectors\Ticimax\TicimaxMapper;
use App\Integrations\Errors\ProviderError;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\RateLimiting\ProviderRateLimiter;
use App\Security\OutboundUrlGuard;
use App\Security\Vault\SecretVault;
use App\Tenancy\Exceptions\TenantMismatch;
use App\Tenancy\TenantContext;
use Closure;
use InvalidArgumentException;

/**
 * Builds the CommerceSource for a tenant's commerce integration. The
 * secret is decrypted (audited) for each build and lives only inside the
 * connector's credentials as a SensitiveValue.
 */
class CommerceSourceFactory
{
    /**
     * @param  (Closure(string): SoapTransport)|null  $transportFactory  base URL => transport; tests inject fakes
     */
    public function __construct(
        private readonly SecretVault $vault,
        private readonly TenantContext $context,
        private readonly ProviderRateLimiter $rateLimiter,
        private readonly OutboundUrlGuard $urlGuard,
        private ?Closure $transportFactory = null,
    ) {}

    public function useTransport(Closure $factory): void
    {
        $this->transportFactory = $factory;
    }

    public function for(TenantIntegration $integration, string $purpose, ?Actor $actor = null): CommerceSource
    {
        if ($integration->tenant_id !== $this->context->requireTenant()) {
            throw new TenantMismatch;
        }

        if ($integration->capability !== 'commerce') {
            throw new InvalidArgumentException('Integration is not a commerce integration.');
        }

        return match ($integration->provider) {
            'ticimax' => $this->ticimax($integration, $purpose, $actor),
            default => throw new InvalidArgumentException("No commerce connector for provider [{$integration->provider}]."),
        };
    }

    private function ticimax(TenantIntegration $integration, string $purpose, ?Actor $actor): TicimaxCommerceConnector
    {
        $settings = $integration->settings;
        $serviceUrl = (string) ($settings['service_url'] ?? '');
        $storefrontUrl = (string) ($settings['storefront_url'] ?? '');

        try {
            $this->urlGuard->assertSafe($serviceUrl);
            $this->urlGuard->assertSafe($storefrontUrl);
        } catch (InvalidArgumentException $e) {
            throw new ProviderError(ProviderError::PERMANENT, 'Ticimax integration settings are invalid: '.$e->getMessage());
        }

        $tenant = Tenant::query()->findOrFail($integration->tenant_id);
        $config = config('commerce.ticimax');

        $credentials = new TicimaxCredentials(
            memberCode: $this->vault->reveal($integration, $purpose, $actor),
            serviceBaseUrl: $serviceUrl,
            storefrontUrl: $storefrontUrl,
            timezone: $tenant->timezone,
        );

        $transport = $this->transportFactory !== null
            ? ($this->transportFactory)($serviceUrl)
            : new PhpSoapTransport($serviceUrl, $config['connect_timeout'], $config['read_timeout'], $config['soap_trace']);

        $client = new TicimaxClient($transport, $credentials, $this->rateLimiter, $integration->tenant_id, $config['max_attempts'], $config['base_backoff_ms']);

        return new TicimaxCommerceConnector(
            $client,
            new TicimaxMapper($tenant->timezone, $storefrontUrl, $config['marketplace_sources']),
            $config['member_page_size'],
            $config['product_page_size'],
            $config['order_page_size'],
            $config['max_cart_pages'],
        );
    }
}
