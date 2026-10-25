<?php

namespace App\Dispatch;

use App\Email\EmailMessageType;
use App\Email\EmailSendGuard;
use App\Push\PushEligibility;
use App\Push\PushSendGuard;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Dispatch plan without any provider call: which allowlisted recipients
 * would be attempted, which are blocked and why, the provider, sender,
 * expected calls, duration and quota impact (from the last stored
 * account status).
 */
final class DispatchPlanner
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PushEligibility $push,
        private readonly EmailSendGuard $emailGuard,
        private readonly PushSendGuard $pushGuard,
    ) {}

    /**
     * @param  list<string>  $recipientIds
     * @return array<string, mixed>
     */
    public function plan(object $campaign, string $channel, array $recipientIds): array
    {
        $tenantId = $this->context->requireTenant();
        $rows = DB::table('test_recipients')->where('tenant_id', $tenantId)->where('channel', $channel)->whereIn('id', $recipientIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $recipientIds)->get();
        $blocked = [];
        $eligible = 0;

        foreach ($rows as $r) {
            $reason = match (true) {
                ! $r->active => 'NOT_ACTIVE_TEST_RECIPIENT',
                $channel === 'email' => $this->emailBlock((string) $r->provider_recipient_id),
                default => ($p = $this->push->forProviderSubscription('onesignal', (string) $r->provider_recipient_id)) === PushEligibility::ALLOWED ? null : $p,
            };
            $reason === null ? $eligible++ : $blocked[$reason] = ($blocked[$reason] ?? 0) + 1;
        }
        $unknown = count(array_unique($recipientIds)) - $rows->count();
        if ($unknown > 0) {
            $blocked['NOT_IN_ALLOWLIST'] = $unknown;
        }

        $integration = $channel === 'email'
            ? DB::table('tenant_integrations')->where('tenant_id', $tenantId)->where('id', $campaign->integration_id)->first()
            : DB::table('tenant_integrations')->where('tenant_id', $tenantId)->where('provider', 'onesignal')->where('capability', 'push')->first();
        $account = (array) (json_decode((string) ($integration->settings ?? '{}'), true)['account'] ?? []);
        $rate = max(1.0, (float) ($account['max_send_rate'] ?? 1));
        $sender = $channel === 'email' ? DB::table('email_sender_identities')->where('tenant_id', $tenantId)->where('id', $campaign->sender_identity_id)->value('from_email') : null;

        return [
            'channel' => $channel,
            'recipients_evaluated' => count(array_unique($recipientIds)),
            'recipients_eligible' => $eligible,
            'recipients_blocked' => array_sum($blocked),
            'blocked_reasons' => $blocked,
            'test_recipients' => $rows->count(),
            'provider' => $integration->provider ?? null,
            'sender' => $sender,
            'estimated_calls' => $eligible,
            'estimated_duration_seconds' => (int) ceil($eligible / $rate),
            'quota' => $channel === 'email' ? [
                'sandbox' => ($account['production_access'] ?? null) === false,
                'max_24h' => $account['max_24h_send'] ?? null,
                'sent_last_24h' => $account['sent_last_24h'] ?? null,
                'after_dispatch' => isset($account['sent_last_24h']) ? (float) $account['sent_last_24h'] + $eligible : null,
                'note' => ($account['production_access'] ?? null) === false ? 'SES sandbox: each test recipient must be a verified SES identity.' : null,
            ] : null,
            'sending_mode' => $channel === 'email' ? $this->emailGuard->mode()->value : $this->pushGuard->mode()->value,
            'provider_calls_made' => 0,
        ];
    }

    private function emailBlock(string $address): ?string
    {
        $tenantId = $this->context->requireTenant();
        $recipient = DB::table('consent_recipients')->where('tenant_id', $tenantId)->where('address_type', 'email')->where('address', strtolower($address))->value('id');
        if ($recipient === null) {
            return null;
        }
        $suppressed = DB::table('suppressions')->where('tenant_id', $tenantId)->where('recipient_id', $recipient)->whereNull('lifted_at')
            ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', 'EMAIL'))->whereIn('source', EmailMessageType::Test->blockingSuppressionSources())->exists();

        return $suppressed ? 'SUPPRESSED' : null;
    }
}
