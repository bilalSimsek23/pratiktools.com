<?php

namespace App\Automation;

use App\Consent\ConsentChannel;
use App\Consent\EligibilityReason;
use App\Consent\SendEligibility;
use App\Email\EmailMessageType;
use App\Messaging\FrequencyCap;
use App\Push\PushEligibility;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * May this customer get a message on this channel now? Evaluated right
 * before an intent is created (never cached from entry time):
 *
 * - e-mail marketing: the Consent Engine (İYS ONAY required; unknown,
 *   unavailable or stale İYS means no), suppressions, frequency cap;
 * - e-mail transactional: a valid address without an address-level
 *   suppression; no marketing consent, no frequency cap;
 * - push: a subscription of a push profile resolved to this customer
 *   (trusted external id only), subscribed and not suppressed; marketing
 *   push is frequency capped.
 *
 * Every "no" carries a category for metrics: identity, consent,
 * suppression or frequency.
 */
final class ActionEligibility
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SendEligibility $email,
        private readonly PushEligibility $push,
        private readonly FrequencyCap $frequency,
    ) {}

    /**
     * @param  array<string, int>  $pending  dry-run: intents already decided, by "channel:recipient"
     * @return array{ok: bool, reason: string, category: ?string, recipient: ?string}
     */
    public function email(string $customerId, string $messageType, array $pending = []): array
    {
        if ($messageType === 'transactional') {
            return $this->transactionalEmail($customerId);
        }

        $decision = $this->email->forCustomer($customerId, ConsentChannel::Email);
        if (! $decision->allowed()) {
            return ['ok' => false, 'reason' => $decision->reason->value, 'category' => self::category($decision->reason), 'recipient' => $decision->recipientId];
        }
        $recipient = (string) $decision->recipientId;
        if ($this->frequency->exceeded('email', $recipient, $pending["email:{$recipient}"] ?? 0)) {
            return ['ok' => false, 'reason' => 'FREQUENCY_CAP', 'category' => 'frequency', 'recipient' => $recipient];
        }

        return ['ok' => true, 'reason' => 'ALLOWED', 'category' => null, 'recipient' => $recipient];
    }

    /**
     * @param  array<string, int>  $pending
     * @return array{ok: bool, reason: string, category: ?string, recipient: ?string}
     */
    public function push(string $customerId, string $messageType, array $pending = []): array
    {
        $tenantId = $this->context->requireTenant();
        $subscriptions = DB::table('push_profiles as p')->join('push_subscriptions as s', fn ($j) => $j->on('s.push_profile_id', '=', 'p.id')->on('s.tenant_id', '=', 'p.tenant_id'))
            ->where('p.tenant_id', $tenantId)->where('p.customer_id', $customerId)->where('p.identity_status', 'resolved')
            ->orderByDesc('s.last_session_at')->get(['s.id', 's.provider', 's.provider_subscription_id']);
        if ($subscriptions->isEmpty()) {
            // No resolved push device: the channel is simply not available
            // (reported as a reason, not counted as an identity block).
            return ['ok' => false, 'reason' => 'NO_PUSH_IDENTITY', 'category' => null, 'recipient' => null];
        }

        $last = PushEligibility::UNKNOWN_SUBSCRIPTION;
        foreach ($subscriptions as $s) {
            $last = $this->push->forProviderSubscription($s->provider, $s->provider_subscription_id);
            if ($last !== PushEligibility::ALLOWED) {
                continue;
            }
            if ($messageType === 'marketing' && $this->frequency->exceeded('push', $customerId, $pending["push:{$customerId}"] ?? 0)) {
                return ['ok' => false, 'reason' => 'FREQUENCY_CAP', 'category' => 'frequency', 'recipient' => (string) $s->id];
            }

            return ['ok' => true, 'reason' => 'ALLOWED', 'category' => null, 'recipient' => (string) $s->id];
        }

        return ['ok' => false, 'reason' => 'PUSH_'.$last, 'category' => $last === PushEligibility::SUPPRESSED ? 'suppression' : 'consent', 'recipient' => null];
    }

    /**
     * @return array{ok: bool, reason: string, category: ?string, recipient: ?string}
     */
    private function transactionalEmail(string $customerId): array
    {
        $tenantId = $this->context->requireTenant();
        $recipient = DB::table('recipient_customers as rc')->join('consent_recipients as r', fn ($j) => $j->on('r.id', '=', 'rc.recipient_id')->on('r.tenant_id', '=', 'rc.tenant_id'))
            ->where('rc.tenant_id', $tenantId)->where('rc.customer_id', $customerId)->where('r.address_type', 'email')->first(['r.id', 'r.is_valid']);
        if ($recipient === null || ! $recipient->is_valid) {
            return ['ok' => false, 'reason' => $recipient === null ? 'NO_RECIPIENT' : 'INVALID_EMAIL', 'category' => 'identity', 'recipient' => $recipient?->id];
        }
        $suppressed = DB::table('suppressions')->where('tenant_id', $tenantId)->where('recipient_id', $recipient->id)->whereNull('lifted_at')
            ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', 'EMAIL'))->whereIn('source', EmailMessageType::Transactional->blockingSuppressionSources())->exists();

        return $suppressed
            ? ['ok' => false, 'reason' => 'SUPPRESSED', 'category' => 'suppression', 'recipient' => $recipient->id]
            : ['ok' => true, 'reason' => 'ALLOWED', 'category' => null, 'recipient' => $recipient->id];
    }

    public static function category(EligibilityReason $reason): string
    {
        return match ($reason) {
            EligibilityReason::LocalSuppressed => 'suppression',
            EligibilityReason::NoRecipient, EligibilityReason::InvalidEmail, EligibilityReason::MarketplaceIdentity, EligibilityReason::IdentityConflict => 'identity',
            default => 'consent',
        };
    }
}
