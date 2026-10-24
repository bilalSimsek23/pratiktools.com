<?php

namespace App\Connectors\Ses;

use App\Email\Contracts\EmailProvider;
use App\Email\Contracts\InspectsSenderIdentities;
use App\Email\Data\ConnectionResult;
use App\Email\Data\ProviderSendResult;
use App\Email\EmailCapabilities;
use App\Email\EmailCapability;
use App\Email\EmailSendAuthorization;
use App\Email\EmailSendBlocked;
use App\Email\EmailSendingMode;
use App\Email\OutboundEmail;
use App\Integrations\Errors\ProviderError;
use App\Models\TenantIntegration;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Amazon SES (API v2) as a delivery provider.
 *
 * Capabilities per the SES documentation: SendEmail with Simple content
 * (subject, HTML and text body, custom headers), message tags, provider
 * templates. Bulk sending (SendBulkEmail) only works with SES templates,
 * so there is no multi-message raw send: send_batch is not declared and
 * sendBatch() makes one call per message (SES also recommends one
 * recipient per call). Delivery, bounce, complaint, open and click events
 * exist only through a configuration set with an event destination (SNS
 * here), so those capabilities depend on the integration's settings.
 */
final class SesEmailProvider implements EmailProvider, InspectsSenderIdentities
{
    public function __construct(
        private readonly SesClient $client,
        private readonly TenantIntegration $integration,
    ) {}

    public function name(): string
    {
        return 'amazon_ses';
    }

    public function client(): SesClient
    {
        return $this->client;
    }

    public function capabilities(): EmailCapabilities
    {
        return self::capabilitiesFor((array) $this->integration->settings);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function capabilitiesFor(array $settings): EmailCapabilities
    {
        $caps = [EmailCapability::SendEmail, EmailCapability::RawHtml, EmailCapability::CustomHeaders, EmailCapability::Tags, EmailCapability::ProviderTemplate];
        if (($settings['configuration_set'] ?? null) !== null && ($settings['sns_topic_arns'] ?? []) !== []) {
            array_push($caps, EmailCapability::WebhookEvents, EmailCapability::BounceEvents, EmailCapability::ComplaintEvents, EmailCapability::DeliveryEvents);
            foreach ((array) ($settings['tracking'] ?? []) as $kind) {
                $caps[] = match ($kind) {
                    'open' => EmailCapability::OpenTracking,
                    'click' => EmailCapability::ClickTracking,
                    default => null,
                };
            }
        }

        return new EmailCapabilities(...array_values(array_filter($caps)));
    }

    public function testConnection(): ConnectionResult
    {
        try {
            $account = $this->client->getAccount();
        } catch (ProviderError $e) {
            return new ConnectionResult(false, errorCategory: $e->category, errorMessage: $e->getMessage());
        }

        $quota = is_array($account['SendQuota'] ?? null) ? $account['SendQuota'] : [];
        $suppression = is_array($account['SuppressionAttributes'] ?? null) ? $account['SuppressionAttributes'] : [];
        $details = is_array($account['Details'] ?? null) ? $account['Details'] : [];
        $review = is_array($details['ReviewDetails'] ?? null) ? $details['ReviewDetails'] : [];

        return new ConnectionResult(true, [
            'region' => $this->client->transport()->credentials()->region,
            'production_access' => (bool) ($account['ProductionAccessEnabled'] ?? false),
            'sending_enabled' => (bool) ($account['SendingEnabled'] ?? false),
            'enforcement_status' => is_string($account['EnforcementStatus'] ?? null) ? $account['EnforcementStatus'] : null,
            'max_24h_send' => (float) ($quota['Max24HourSend'] ?? 0),
            'max_send_rate' => (float) ($quota['MaxSendRate'] ?? 0),
            'sent_last_24h' => (float) ($quota['SentLast24Hours'] ?? 0),
            'account_suppressed_reasons' => array_values(array_filter((array) ($suppression['SuppressedReasons'] ?? []), 'is_string')),
            'mail_type' => is_string($details['MailType'] ?? null) ? $details['MailType'] : null,
            'review_status' => is_string($review['Status'] ?? null) ? $review['Status'] : null,
            'checked_at' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }

    public function inspectIdentity(string $providerIdentityId, string $domain): array
    {
        return SesIdentityRecords::from($this->client->getEmailIdentity($providerIdentityId), $domain, $this->client->transport()->credentials()->region);
    }

    public function send(EmailSendAuthorization $authorization, OutboundEmail $email): ProviderSendResult
    {
        // Second kill switch, independent of the guard.
        if (EmailSendingMode::platform() === EmailSendingMode::Off) {
            throw new EmailSendBlocked('SENDING_MODE_OFF', 'E-mail sending is off (EMAIL_SENDING_MODE).');
        }
        if ($authorization->tenantId !== $this->integration->tenant_id || ! $authorization->covers($email, $this->integration->id)) {
            throw new EmailSendBlocked('AUTHORIZATION_MISMATCH', 'The send authorization does not cover this message.');
        }

        $before = $this->client->transport()->apiCalls();
        $id = $this->client->sendEmail($this->payload($email));

        return new ProviderSendResult($id, $this->client->transport()->apiCalls() - $before);
    }

    public function sendBatch(array $messages): array
    {
        $results = [];
        foreach ($messages as [$authorization, $email]) {
            try {
                $results[] = $this->send($authorization, $email);
            } catch (Throwable $e) {
                $results[] = $e;
            }
        }

        return $results;
    }

    public function health(): array
    {
        $account = (array) ($this->integration->settings['account'] ?? []);
        $checked = isset($account['checked_at']) ? CarbonImmutable::parse((string) $account['checked_at']) : null;
        $stale = $checked === null || $checked->lt(now()->subMinutes((int) config('email.ses.account_max_age_minutes')));

        return [
            'provider' => 'amazon_ses',
            'status' => match (true) {
                $checked === null => 'unknown',
                ($account['enforcement_status'] ?? null) === 'SHUTDOWN', ($account['sending_enabled'] ?? true) === false => 'fail',
                ($account['enforcement_status'] ?? null) === 'PROBATION', $stale => 'warn',
                default => 'ok',
            },
            'account_checked_at' => $checked?->toIso8601String(),
            'stale' => $stale,
            'production_access' => $account['production_access'] ?? null,
            'enforcement_status' => $account['enforcement_status'] ?? null,
        ];
    }

    /**
     * SendEmail request with Simple content. One recipient per call; the
     * message id is also sent as a tag so events map back without trusting
     * provider ids alone.
     *
     * @return array<string, mixed>
     */
    public function payload(OutboundEmail $email): array
    {
        $body = ['Text' => ['Charset' => 'UTF-8', 'Data' => $email->text]];
        if ($email->html !== null) {
            $body['Html'] = ['Charset' => 'UTF-8', 'Data' => $email->html];
        }

        $tags = [['Name' => 'pt_message_id', 'Value' => $email->messageId], ['Name' => 'pt_type', 'Value' => $email->type->value]];
        foreach ($email->tags as $name => $value) {
            $tags[] = ['Name' => $name, 'Value' => $value];
        }

        $simple = ['Subject' => ['Charset' => 'UTF-8', 'Data' => $email->subject], 'Body' => $body];
        if ($email->headers !== []) {
            $simple['Headers'] = array_map(fn ($name, $value) => ['Name' => $name, 'Value' => $value], array_keys($email->headers), $email->headers);
        }

        return array_filter([
            'FromEmailAddress' => self::from($email->fromEmail, $email->fromName),
            'Destination' => ['ToAddresses' => [$email->to]],
            'ReplyToAddresses' => $email->replyTo !== null ? [$email->replyTo] : null,
            'Content' => ['Simple' => $simple],
            'EmailTags' => $tags,
            'ConfigurationSetName' => $this->client->transport()->credentials()->configurationSet,
        ], fn ($v) => $v !== null);
    }

    /**
     * "Name <address>", the name as an RFC 2047 encoded word unless it is
     * plain ASCII (Turkish names are not).
     */
    public static function from(string $address, ?string $name): string
    {
        if ($name === null) {
            return $address;
        }

        return preg_match('/^[A-Za-z0-9 .\-]+$/', $name) === 1
            ? "\"{$name}\" <{$address}>"
            : '=?UTF-8?B?'.base64_encode($name)."?= <{$address}>";
    }
}
