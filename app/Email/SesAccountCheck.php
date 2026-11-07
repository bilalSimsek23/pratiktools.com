<?php

namespace App\Email;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Connectors\Ses\SesEmailProvider;
use App\Email\Data\ConnectionResult;
use App\Integrations\Errors\ProviderError;
use App\Models\TenantIntegration;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The read-only SES check behind email:ses check and the Super Admin
 * "SES Bağlantısını Test Et" button: GetAccount (sandbox, quota, rate,
 * enforcement), then, when a configuration set is named, GetConfigurationSet
 * and its event destinations. Nothing at SES is created or changed.
 *
 * The result is stored on the integration (status, last_verified_at,
 * last_error, settings.account, settings.configuration_set_check); the
 * audit trail gets the account numbers only.
 */
final class SesAccountCheck
{
    public const ACCOUNT_FIELDS = ['region', 'production_access', 'sending_enabled', 'enforcement_status', 'max_24h_send', 'max_send_rate'];

    /** SES event types a bounce/complaint pipeline needs on the SNS destination. */
    public const REQUIRED_EVENTS = ['BOUNCE', 'COMPLAINT'];

    public function __construct(
        private readonly EmailProviderResolver $providers,
        private readonly AuditLogger $audit,
    ) {}

    public function run(TenantIntegration $integration, Actor $actor): ConnectionResult
    {
        $provider = null;
        try {
            $provider = $this->providers->provider($integration, 'connection_test', $actor);
            $result = $provider->testConnection();
        } catch (ProviderError $e) {
            $result = new ConnectionResult(false, errorCategory: $e->category, errorMessage: $e->getMessage());
        } catch (InvalidArgumentException) {
            $result = new ConnectionResult(false, errorCategory: ProviderError::PERMANENT, errorMessage: 'The stored SES credentials or settings are invalid; save them again.');
        }

        if (! $result->ok) {
            $integration->forceFill(['status' => $result->errorCategory === ProviderError::AUTH ? 'action_required' : 'error', 'last_error' => $result->errorMessage])->save();

            return $result;
        }

        $settings = ['account' => $result->account] + (array) $integration->settings;
        $name = $settings['configuration_set'] ?? null;
        $settings['configuration_set_check'] = is_string($name) && $provider instanceof SesEmailProvider
            ? $this->configurationSet($provider, $name, (array) ($settings['sns_topic_arns'] ?? []))
            : ['state' => 'not_configured', 'checked_at' => CarbonImmutable::now()->toIso8601String()];

        $integration->forceFill(['status' => 'connected', 'last_error' => null, 'last_verified_at' => now(), 'settings' => $settings])->save();
        $this->audit->record(AuditAction::EmailAccountChecked, 'tenant_integration', $integration->id, array_intersect_key($result->account, array_flip(self::ACCOUNT_FIELDS))
            + ['configuration_set' => $settings['configuration_set_check']['state']], $actor);

        return $result;
    }

    /**
     * State: ok | missing (no such set at SES) | iam_required (the key may
     * not read it) | error. Destinations: the SNS ones, with their event
     * types and whether the topic is one we accept.
     *
     * @param  list<string>  $topics
     * @return array<string, mixed>
     */
    private function configurationSet(SesEmailProvider $provider, string $name, array $topics): array
    {
        $checked = ['checked_at' => CarbonImmutable::now()->toIso8601String()];
        try {
            $set = $provider->client()->getConfigurationSet($name);
            $destinations = $provider->client()->getConfigurationSetEventDestinations($name);
        } catch (ProviderError $e) {
            return ['state' => match (true) {
                str_contains($e->getMessage(), 'NotFoundException') => 'missing',
                $e->category === ProviderError::AUTH => 'iam_required',
                default => 'error',
            }, 'error' => $e->getMessage()] + $checked;
        }

        $sns = [];
        foreach ((array) ($destinations['EventDestinations'] ?? []) as $d) {
            $arn = is_array($d) ? ($d['SnsDestination']['TopicArn'] ?? null) : null;
            if (! is_string($arn)) {
                continue;
            }
            $sns[] = [
                'name' => is_string($d['Name'] ?? null) ? mb_substr($d['Name'], 0, 64) : null,
                'enabled' => ($d['Enabled'] ?? false) === true,
                'topic_arn' => $arn,
                'topic_allowed' => in_array($arn, $topics, true),
                'events' => array_values(array_filter((array) ($d['MatchingEventTypes'] ?? []), 'is_string')),
            ];
        }
        $events = [];
        foreach ($sns as $d) {
            if ($d['enabled'] && $d['topic_allowed']) {
                $events = [...$events, ...$d['events']];
            }
        }

        return [
            'state' => 'ok',
            'sending_enabled' => ($set['SendingOptions']['SendingEnabled'] ?? true) === true,
            'reputation_metrics' => ($set['ReputationOptions']['ReputationMetricsEnabled'] ?? false) === true,
            'custom_redirect_domain' => is_string($set['TrackingOptions']['CustomRedirectDomain'] ?? null) ? $set['TrackingOptions']['CustomRedirectDomain'] : null,
            'sns_destinations' => $sns,
            // Bounce and complaint reach us only through an enabled destination on an accepted topic.
            'bounce_complaint' => array_diff(self::REQUIRED_EVENTS, $events) === [],
            'open_click' => array_values(array_intersect(['OPEN', 'CLICK'], $events)),
        ] + $checked;
    }
}
