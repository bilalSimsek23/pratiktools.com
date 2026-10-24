<?php

namespace App\Connectors\Ses;

use App\Audit\Actor;
use App\Email\Contracts\EmailProviderFactory;
use App\Email\EmailSendBlocked;
use App\Email\EmailSendingMode;
use App\Integrations\Errors\ProviderError;
use App\Models\TenantIntegration;
use App\RateLimiting\ProviderRateLimiter;
use App\RateLimiting\TokenBucketLimiter;
use App\Security\Vault\SecretVault;
use App\Tenancy\Exceptions\TenantMismatch;
use App\Tenancy\TenantContext;
use Illuminate\Http\Client\Factory as Http;

/**
 * Builds the SES provider for a tenant integration. Settings (public):
 * region, configuration_set, sns_topic_arns, account (last check). Secret
 * (vault): the IAM access key pair. For the "email_send" purpose the key
 * is not even decrypted while EMAIL_SENDING_MODE is off.
 */
class SesClientFactory implements EmailProviderFactory
{
    public const SEND_PURPOSE = 'email_send';

    public function __construct(
        private readonly SecretVault $vault,
        private readonly TenantContext $context,
        private readonly ProviderRateLimiter $rateLimiter,
        private readonly TokenBucketLimiter $buckets,
        private readonly Http $http,
    ) {}

    public function make(TenantIntegration $integration, string $purpose, ?Actor $actor = null): SesEmailProvider
    {
        if ($integration->tenant_id !== $this->context->requireTenant()) {
            throw new TenantMismatch;
        }
        if ($integration->provider !== 'amazon_ses' || $integration->capability !== 'email') {
            throw new \InvalidArgumentException('Integration is not an Amazon SES e-mail integration.');
        }
        if ($purpose === self::SEND_PURPOSE && EmailSendingMode::platform() === EmailSendingMode::Off) {
            throw new EmailSendBlocked('SENDING_MODE_OFF', 'E-mail sending is off (EMAIL_SENDING_MODE).');
        }

        $settings = (array) $integration->settings;
        $region = (string) ($settings['region'] ?? '');
        if (! SesCredentials::validRegion($region)) {
            throw new ProviderError(ProviderError::PERMANENT, 'The SES integration needs a valid region setting.');
        }

        $credentials = SesCredentials::fromSecret($integration->id, $region, $this->vault->reveal($integration, $purpose, $actor)->reveal(), $settings['configuration_set'] ?? null);
        $config = config('email.ses');

        return new SesEmailProvider(new SesClient(new SesTransport(
            $this->http,
            $credentials,
            $this->rateLimiter,
            $this->buckets,
            $integration->tenant_id,
            $this->sendRate($settings),
            (int) $config['connect_timeout'],
            (int) $config['timeout'],
            (int) $config['max_attempts'],
            (int) $config['base_backoff_ms'],
            app()->bound('ses.sleep') ? app('ses.sleep') : null,
        )), $integration);
    }

    /**
     * Sends per second: a share of the account's MaxSendRate from the last
     * account check, capped locally; 1/s while unknown (the sandbox rate).
     *
     * @param  array<string, mixed>  $settings
     */
    private function sendRate(array $settings): float
    {
        $max = (float) (($settings['account']['max_send_rate'] ?? 0));
        $rate = $max > 0 ? $max * (float) config('email.ses.send_rate_share') : 1.0;

        return max(0.2, min($rate, (float) config('email.ses.max_send_rate')));
    }
}
