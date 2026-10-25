<?php

namespace App\Dispatch;

use App\Approvals\CampaignApprovals;
use App\Email\EmailSendGuard;
use App\Email\EmailSendingMode;
use App\Push\PushSendGuard;
use App\Push\PushSendingMode;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Checked when a dispatch is queued and again right before it hands
 * messages to the F4/F3 delivery pipelines (which then apply their own
 * per-message guards: allowlist, suppression, consent rules, quota):
 *
 * - live is refused outright in this phase (LIVE_AVAILABLE is a constant,
 *   not configuration, so no setting, role or Super Admin can enable it);
 * - the effective channel sending mode is exactly test_only;
 * - the campaign is still frozen with the same fingerprint and snapshot;
 * - enough valid approvals by distinct people (never the creator or the
 *   requester); no rejection on the current fingerprint;
 * - every recipient is an active entry of the canonical test allowlist;
 * - e-mail: verified sender of a connected integration with send scope.
 */
final class DispatchGuard
{
    public const LIVE_AVAILABLE = false;

    public function __construct(
        private readonly TenantContext $context,
        private readonly CampaignApprovals $approvals,
        private readonly EmailSendGuard $email,
        private readonly PushSendGuard $push,
    ) {}

    public function assert(object $dispatch, object $campaign): void
    {
        $this->assertMode((string) $dispatch->mode, (string) $dispatch->channel);
        if (! in_array($campaign->status, ['ready', 'scheduled'], true) || $campaign->frozen_at === null) {
            throw new DispatchBlocked('CAMPAIGN_NOT_FROZEN', 'The campaign is not ready (frozen).');
        }
        if ($dispatch->fingerprint !== CampaignApprovals::fingerprint($campaign) || $dispatch->segment_snapshot_id !== $campaign->segment_snapshot_id) {
            throw new DispatchBlocked('CAMPAIGN_CHANGED', 'The campaign changed after this dispatch was requested.');
        }
        if (! $this->approvalsSatisfied($campaign, (string) $dispatch->mode, (string) $dispatch->requested_by)) {
            throw new DispatchBlocked('APPROVALS_MISSING', 'Required approvals by distinct people are missing, expired or rejected.');
        }
        $this->assertRecipients((string) $dispatch->channel, (array) json_decode((string) $dispatch->recipients, true));
        $this->assertProvider($campaign, (string) $dispatch->channel);
    }

    /**
     * $sendingMode=false checks only the dispatch mode: a plan may be
     * requested while sending is off; queueing and execution check both.
     */
    public function assertMode(string $mode, string $channel, bool $sendingMode = true): void
    {
        // Only test_only exists in this phase; live (or anything else) is refused.
        if ($mode !== 'test_only') {
            throw new DispatchBlocked('LIVE_DISABLED', 'Live sending is disabled until external readiness requirements are satisfied.');
        }
        if (! $sendingMode) {
            return;
        }
        $effective = $channel === 'email' ? $this->email->mode()->value : $this->push->mode()->value;
        if ($effective !== ($channel === 'email' ? EmailSendingMode::TestOnly->value : PushSendingMode::TestOnly->value)) {
            throw new DispatchBlocked('SENDING_MODE_NOT_TEST_ONLY', "The effective {$channel} sending mode is {$effective}; test dispatch needs test_only.");
        }
    }

    public function approvalsSatisfied(object $campaign, string $mode, string $requesterUserId): bool
    {
        $required = (int) (config('dispatch.required_approvals')[$mode] ?? PHP_INT_MAX);

        return ! $this->approvals->rejected($campaign) && $this->approvals->valid($campaign, $requesterUserId)->count() >= $required;
    }

    /**
     * @param  list<string>  $recipientIds  test_recipients ids
     */
    public function assertRecipients(string $channel, array $recipientIds): void
    {
        $active = DB::table('test_recipients')->where('tenant_id', $this->context->requireTenant())->where('channel', $channel)->where('active', true)
            ->whereIn('id', $recipientIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $recipientIds)->count();
        if ($recipientIds === [] || $active !== count(array_unique($recipientIds))) {
            throw new DispatchBlocked('RECIPIENT_NOT_ALLOWLISTED', 'Every recipient must be an active entry of the test allowlist.');
        }
    }

    private function assertProvider(object $campaign, string $channel): void
    {
        $tenantId = $this->context->requireTenant();
        if ($channel === 'email') {
            $sender = DB::table('email_sender_identities')->where('tenant_id', $tenantId)->where('id', $campaign->sender_identity_id)->first();
            $integration = DB::table('tenant_integrations')->where('tenant_id', $tenantId)->where('id', $campaign->integration_id)->first();
            if ($sender === null || $sender->verification_status !== 'verified') {
                throw new DispatchBlocked('SENDER_NOT_VERIFIED', 'The sender identity is not verified.');
            }
            if ($integration === null || $integration->status !== 'connected' || ! in_array(EmailSendGuard::SCOPE, (array) json_decode($integration->scopes, true), true)) {
                throw new DispatchBlocked('PROVIDER_NOT_CONNECTED', 'The e-mail integration is not connected with the send_email scope.');
            }

            return;
        }
        $push = DB::table('tenant_integrations')->where('tenant_id', $tenantId)->where('provider', 'onesignal')->where('capability', 'push')->first();
        if ($push === null || $push->status !== 'connected' || ! in_array(PushSendGuard::SCOPE, (array) json_decode($push->scopes, true), true)) {
            throw new DispatchBlocked('PROVIDER_NOT_CONNECTED', 'OneSignal is not connected with the send_push scope.');
        }
    }
}
