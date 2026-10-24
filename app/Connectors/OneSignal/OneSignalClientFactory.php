<?php

namespace App\Connectors\OneSignal;

use App\Audit\Actor;
use App\Integrations\Errors\ProviderError;
use App\Models\TenantIntegration;
use App\Push\PushSendBlocked;
use App\Push\PushSendGuard;
use App\Push\PushSendingMode;
use App\RateLimiting\ProviderRateLimiter;
use App\Security\SensitiveValue;
use App\Security\Vault\SecretVault;
use App\Tenancy\Exceptions\TenantMismatch;
use App\Tenancy\TenantContext;
use Illuminate\Http\Client\Factory as Http;

/**
 * Builds OneSignal clients for a tenant's push integration. Settings:
 * app_id (public). Secret (vault): the App API key. The push client is
 * only built when sending is not switched off and the integration holds
 * the send_push scope; otherwise the key is not even decrypted.
 */
class OneSignalClientFactory
{
    public function __construct(
        private readonly SecretVault $vault,
        private readonly TenantContext $context,
        private readonly ProviderRateLimiter $rateLimiter,
        private readonly Http $http,
    ) {}

    public function read(TenantIntegration $integration, string $purpose, ?Actor $actor = null): OneSignalReadClient
    {
        return new OneSignalReadClient($this->transport($integration, $purpose, $actor), $this->http, $this->sleeper());
    }

    public function push(TenantIntegration $integration, ?Actor $actor = null): OneSignalPushClient
    {
        if (PushSendingMode::platform() === PushSendingMode::Off) {
            throw new PushSendBlocked('SENDING_MODE_OFF', 'Push sending is off (PUSH_SENDING_MODE).');
        }
        if (! in_array(PushSendGuard::SCOPE, (array) $integration->scopes, true)) {
            throw new PushSendBlocked('SEND_SCOPE_MISSING', 'The integration does not have the send_push scope.');
        }

        return new OneSignalPushClient($this->transport($integration, 'push_send', $actor));
    }

    private function transport(TenantIntegration $integration, string $purpose, ?Actor $actor): OneSignalTransport
    {
        if ($integration->tenant_id !== $this->context->requireTenant()) {
            throw new TenantMismatch;
        }
        if ($integration->provider !== 'onesignal' || $integration->capability !== 'push') {
            throw new \InvalidArgumentException('Integration is not a OneSignal push integration.');
        }

        $appId = (string) ($integration->settings['app_id'] ?? '');
        if ($appId === '') {
            throw new ProviderError(ProviderError::PERMANENT, 'OneSignal integration needs the app_id setting.');
        }

        $config = config('push.onesignal');

        return new OneSignalTransport(
            $this->http,
            new OneSignalCredentials($integration->id, $appId, new SensitiveValue($this->vault->reveal($integration, $purpose, $actor)->reveal())),
            $this->rateLimiter,
            $integration->tenant_id,
            (int) $config['connect_timeout'],
            (int) $config['timeout'],
            (int) $config['max_attempts'],
            (int) $config['base_backoff_ms'],
            $this->sleeper(),
        );
    }

    /**
     * Tests bind "onesignal.sleep" to avoid real waits.
     */
    private function sleeper(): ?\Closure
    {
        return app()->bound('onesignal.sleep') ? app('onesignal.sleep') : null;
    }
}
