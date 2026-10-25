<?php

namespace App\Email;

use App\Consent\ConsentChannel;
use App\Consent\SendEligibility;
use App\Messaging\FrequencyCap;
use App\Models\TenantIntegration;
use App\Models\TenantSetting;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether one e-mail may be sent. Checked before any credential is
 * decrypted or request built, and again at send time:
 *
 *  1. effective sending mode (platform EMAIL_SENDING_MODE and tenant mode)
 *     is not off;
 *  2. the message's own integration is connected, holds send_email and
 *     declares the capabilities the message needs;
 *  3. the sender identity is verified (and DKIM for marketing);
 *  4. test messages, and every message in test_only mode, go only to
 *     active test recipients. That is the only consent exception, and it
 *     applies to type "test" only: marketing is never exempt;
 *  5. no active suppression that blocks this message type;
 *  6. marketing: the Consent Engine allows it (fail-closed: İYS unknown
 *     or unavailable means no), it carries a one-click unsubscribe, it is
 *     within the frequency cap and the tenant's bounce/complaint rates;
 *  7. provider account: not paused, not in the sandbox for live marketing,
 *     and quota left (otherwise the message is deferred, not lost).
 */
final class EmailSendGuard
{
    public const SCOPE = 'send_email';

    public function __construct(
        private readonly TenantContext $context,
        private readonly SendEligibility $eligibility,
        private readonly EmailProviderResolver $providers,
        private readonly UnsubscribeTokens $unsubscribe,
        private readonly FrequencyCap $frequency,
    ) {}

    public function mode(): EmailSendingMode
    {
        $tenantMode = TenantSetting::query()->value('sending_mode');

        return EmailSendingMode::forTenant(is_string($tenantMode) ? $tenantMode : null);
    }

    public function authorize(TenantIntegration $integration, object $message, OutboundEmail $email): EmailSendAuthorization
    {
        $tenantId = $this->context->requireTenant();
        $mode = $this->mode();
        $type = $email->type;

        if ($mode === EmailSendingMode::Off) {
            throw new EmailSendBlocked('SENDING_MODE_OFF', 'E-mail sending is off (EMAIL_SENDING_MODE / tenant sending_mode).');
        }
        if ($integration->tenant_id !== $tenantId || $integration->capability !== 'email' || $integration->status !== 'connected' || $message->integration_id !== $integration->id) {
            throw new EmailSendBlocked('PROVIDER_NOT_CONNECTED', 'The message\'s e-mail integration is not connected.');
        }
        if (! in_array(self::SCOPE, (array) $integration->scopes, true)) {
            throw new EmailSendBlocked('SEND_SCOPE_MISSING', 'The integration does not have the send_email scope.');
        }
        $this->capabilities($integration, $email);
        $this->sender($integration, $message, $email);

        $testRecipient = $this->isTestRecipient($email->to);
        if (($type === EmailMessageType::Test || $mode === EmailSendingMode::TestOnly) && ! $testRecipient) {
            throw new EmailSendBlocked('NOT_TEST_RECIPIENT', 'Only active test recipients may receive this message.');
        }

        $this->suppressions($email, $message->recipient_id);

        if ($type->requiresMarketingConsent()) {
            $this->marketing($message, $email, $mode);
        }

        $this->account($integration, $type, $mode, $testRecipient);

        $integrationId = $integration->id;
        $messageId = $email->messageId;
        $to = $email->to;

        return \Closure::bind(
            fn () => new EmailSendAuthorization($tenantId, $integrationId, $messageId, $to, $mode, $type),
            null,
            EmailSendAuthorization::class,
        )();
    }

    public function isTestRecipient(string $address): bool
    {
        return DB::table('test_recipients')->where('tenant_id', $this->context->requireTenant())->where('channel', 'email')->where('provider', '*')
            ->where('active', true)->where('provider_recipient_id', strtolower($address))->exists();
    }

    private function capabilities(TenantIntegration $integration, OutboundEmail $email): void
    {
        $caps = $this->providers->capabilities($integration);
        $needed = array_filter([
            EmailCapability::SendEmail,
            $email->html !== null ? EmailCapability::RawHtml : null,
            $email->headers !== [] ? EmailCapability::CustomHeaders : null,
        ]);
        foreach ($needed as $capability) {
            if (! $caps->has($capability)) {
                throw new EmailSendBlocked('CAPABILITY_MISSING', "The provider does not support {$capability->value}.");
            }
        }
    }

    private function sender(TenantIntegration $integration, object $message, OutboundEmail $email): void
    {
        $identity = DB::table('email_sender_identities')->where('tenant_id', $this->context->requireTenant())
            ->where('id', $message->sender_identity_id)->where('integration_id', $integration->id)->first();

        if ($identity === null || $identity->verification_status !== 'verified' || $identity->from_email !== $email->fromEmail) {
            throw new EmailSendBlocked('SENDER_NOT_VERIFIED', 'The sender identity is not verified at the provider.');
        }
        if ($email->type === EmailMessageType::Marketing && $identity->dkim_status !== 'verified') {
            throw new EmailSendBlocked('DKIM_NOT_VERIFIED', 'Marketing mail needs a DKIM-verified sending domain.');
        }
    }

    private function suppressions(OutboundEmail $email, ?string $recipientId): void
    {
        $recipientId ??= DB::table('consent_recipients')->where('tenant_id', $this->context->requireTenant())
            ->where('address_type', 'email')->where('address', $email->to)->value('id');
        if ($recipientId === null) {
            return;
        }

        $suppressed = DB::table('suppressions')->where('tenant_id', $this->context->requireTenant())->where('recipient_id', $recipientId)
            ->whereNull('lifted_at')->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', ConsentChannel::Email->value))
            ->whereIn('source', $email->type->blockingSuppressionSources())->exists();
        if ($suppressed) {
            throw new EmailSendBlocked('SUPPRESSED', 'The recipient is suppressed for this kind of message.');
        }
    }

    private function marketing(object $message, OutboundEmail $email, EmailSendingMode $mode): void
    {
        if ($message->recipient_id === null) {
            throw new EmailSendBlocked('NO_RECIPIENT', 'Marketing mail needs a consent recipient.');
        }
        $decision = $this->eligibility->forRecipient((string) $message->recipient_id, ConsentChannel::Email);
        if (! $decision->allowed()) {
            throw new EmailSendBlocked($decision->reason->value, "The Consent Engine does not allow marketing mail ({$decision->reason->value}).");
        }

        if (! $this->unsubscribe->available()) {
            throw new EmailSendBlocked('UNSUBSCRIBE_UNAVAILABLE', 'EMAIL_UNSUBSCRIBE_KEY is not configured: no marketing mail without a working unsubscribe.');
        }
        $unsubscribe = $email->headers['List-Unsubscribe'] ?? '';
        if (! str_contains($unsubscribe, '<https://') || ($email->headers['List-Unsubscribe-Post'] ?? null) !== 'List-Unsubscribe=One-Click') {
            throw new EmailSendBlocked('UNSUBSCRIBE_MISSING', 'Marketing mail needs a one-click unsubscribe header.');
        }

        if ($this->frequency->exceeded('email', (string) $message->recipient_id)) {
            throw new EmailSendBlocked('FREQUENCY_CAP', 'The recipient reached the marketing frequency cap.');
        }

        if ($mode === EmailSendingMode::Live) {
            $this->reputation();
        }
    }

    /**
     * Tenant-wide bounce and complaint rates over the guard window, from our
     * own events; only judged once the sample is large enough.
     */
    private function reputation(): void
    {
        $g = (array) config('email.guardrails');
        $since = now()->subDays((int) $g['window_days']);
        $sent = DB::table('email_messages')->where('tenant_id', $this->context->requireTenant())->where('status', 'sent')->where('sent_at', '>=', $since)->count();
        if ($sent < (int) $g['sample_size']) {
            return;
        }

        $events = DB::table('email_events')->where('tenant_id', $this->context->requireTenant())->where('occurred_at', '>=', $since)
            ->selectRaw("count(*) FILTER (WHERE event_type = 'EMAIL_BOUNCED' AND bounce_class = 'hard') AS bounces, count(*) FILTER (WHERE event_type = 'EMAIL_COMPLAINT') AS complaints")->first();
        if ((int) $events->bounces / $sent > (float) $g['max_bounce_rate']) {
            throw new EmailSendBlocked('BOUNCE_RATE_TOO_HIGH', 'Hard bounce rate is above the safety threshold.');
        }
        if ((int) $events->complaints / $sent > (float) $g['max_complaint_rate']) {
            throw new EmailSendBlocked('COMPLAINT_RATE_TOO_HIGH', 'Complaint rate is above the safety threshold.');
        }
    }

    private function account(TenantIntegration $integration, EmailMessageType $type, EmailSendingMode $mode, bool $testRecipient): void
    {
        $account = (array) ($integration->settings['account'] ?? []);
        $checkedAt = isset($account['checked_at']) ? CarbonImmutable::parse((string) $account['checked_at']) : null;
        $fresh = $checkedAt !== null && $checkedAt->gte(now()->subMinutes((int) config('email.ses.account_max_age_minutes')));
        $liveMarketing = $type === EmailMessageType::Marketing && $mode === EmailSendingMode::Live;

        if ($checkedAt !== null && (($account['enforcement_status'] ?? null) === 'SHUTDOWN' || ($account['sending_enabled'] ?? true) === false)) {
            throw new EmailSendBlocked('PROVIDER_SENDING_PAUSED', 'Sending is paused for the provider account.');
        }
        if ($liveMarketing && ! $fresh) {
            throw new EmailSendBlocked('PROVIDER_STATUS_UNKNOWN', 'The provider account status (quota, sandbox) is unknown or stale.');
        }
        if ($liveMarketing && ($account['production_access'] ?? false) !== true && ! $testRecipient) {
            throw new EmailSendBlocked('PROVIDER_SANDBOX', 'The provider account is in the sandbox: no mail to the public.');
        }
        if ($checkedAt === null) {
            return;
        }

        $max = (float) ($account['max_24h_send'] ?? 0);
        $sentSince = DB::table('email_messages')->where('tenant_id', $this->context->requireTenant())->where('integration_id', $integration->id)
            ->where('status', 'sent')->where('sent_at', '>=', $checkedAt)->count();
        $used = (float) ($account['sent_last_24h'] ?? 0) + $sentSince;
        $limit = $type === EmailMessageType::Marketing ? $max * (1 - (float) config('email.guardrails.quota_reserve')) : $max;
        if ($max > 0 && $used >= $limit) {
            throw new EmailSendBlocked('QUOTA_EXHAUSTED', 'The provider\'s 24-hour sending quota is used up.', CarbonImmutable::now()->addHour());
        }
    }
}
