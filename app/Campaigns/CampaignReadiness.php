<?php

namespace App\Campaigns;

use App\Consent\ConsentStrategy;
use App\Email\EmailSendGuard;
use App\Email\EmailSendingMode;
use App\Email\UnsubscribeTokens;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Why a campaign is or is not ready, as machine-readable checks:
 *
 *   preparation (must PASS before the campaign may become ready/scheduled):
 *     CONTENT, AUDIENCE, SENDER, DKIM, UNSUBSCRIBE
 *   advisory: MAIL_FROM (custom MAIL FROM missing never blocks)
 *   delivery (must PASS before anything could ever be sent):
 *     IYS, ELIGIBLE_AUDIENCE, SES_PRODUCTION_ACCESS, EMAIL_SENDING_MODE, DISPATCH
 *
 * DISPATCH is always BLOCKED in F6: there is no campaign dispatcher, so
 * even an all-PASS campaign sends nothing.
 */
final class CampaignReadiness
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CampaignAudience $audience,
        private readonly EmailSendGuard $guard,
        private readonly UnsubscribeTokens $unsubscribe,
        private readonly ConsentStrategy $strategy,
    ) {}

    /**
     * @param  array<string, mixed>|null  $audienceReport  reuse a computed report
     * @return array{can_prepare: bool, sendable: bool, reason_codes: list<string>, checks: list<array{check: string, status: string, reason: ?string, group: string}>, consent: array<string, mixed>}
     */
    public function evaluate(object $campaign, ?array $audienceReport = null): array
    {
        $tenantId = $this->context->requireTenant();
        $checks = [];
        $add = function (string $check, string $group, bool $ok, ?string $reason, bool $warnOnly = false) use (&$checks) {
            $checks[] = ['check' => $check, 'status' => $ok ? 'PASS' : ($warnOnly ? 'WARN' : 'BLOCKED'), 'reason' => $ok ? null : $reason, 'group' => $group];
        };

        $version = $campaign->template_version_id === null ? null : DB::table('email_template_versions as v')
            ->join('email_templates as t', fn ($j) => $j->on('t.id', '=', 'v.template_id')->on('t.tenant_id', '=', 'v.tenant_id'))
            ->where('v.tenant_id', $tenantId)->where('v.id', $campaign->template_version_id)->first(['v.status', 't.message_type']);
        $add('CONTENT', 'preparation', $version !== null && $version->status === 'published' && $version->message_type === 'marketing',
            $version === null ? 'CONTENT_MISSING' : ($version->message_type !== 'marketing' ? 'CONTENT_NOT_MARKETING' : 'CONTENT_NOT_PUBLISHED'));

        try {
            $report = $audienceReport ?? $this->audience->report($campaign);
            $add('AUDIENCE', 'preparation', $report['raw_audience'] > 0, 'AUDIENCE_EMPTY');
        } catch (CampaignError $e) {
            $report = null;
            $add('AUDIENCE', 'preparation', false, $e->reason);
        }

        $sender = $campaign->sender_identity_id === null ? null : DB::table('email_sender_identities')->where('tenant_id', $tenantId)
            ->where('id', $campaign->sender_identity_id)->first(['integration_id', 'verification_status', 'dkim_status', 'mail_from_status']);
        $add('SENDER', 'preparation', $sender !== null && $sender->verification_status === 'verified' && $sender->integration_id === $campaign->integration_id,
            $sender === null ? 'SENDER_MISSING' : ($sender->integration_id !== $campaign->integration_id ? 'SENDER_INTEGRATION_MISMATCH' : 'SENDER_NOT_VERIFIED'));
        $add('DKIM', 'preparation', $sender !== null && $sender->dkim_status === 'verified', 'DKIM_NOT_VERIFIED');
        $add('MAIL_FROM', 'advisory', $sender !== null && $sender->mail_from_status === 'verified', 'MAIL_FROM_NOT_CONFIGURED', warnOnly: true);
        $add('UNSUBSCRIBE', 'preparation', $this->unsubscribe->available(), 'EMAIL_UNSUBSCRIBE_KEY_MISSING');

        $consent = $this->strategy->state();
        $add('IYS', 'delivery', $consent['ready'], $consent['blocker'] ?? 'IYS_UNAVAILABLE');
        $add('ELIGIBLE_AUDIENCE', 'delivery', $report !== null && $report['currently_eligible'] > 0, 'NO_ELIGIBLE_RECIPIENTS');
        $account = (array) (json_decode((string) DB::table('tenant_integrations')->where('tenant_id', $tenantId)->where('id', $campaign->integration_id)->value('settings'), true)['account'] ?? []);
        $add('SES_PRODUCTION_ACCESS', 'delivery', ($account['production_access'] ?? null) === true, $account === [] ? 'PROVIDER_STATUS_UNKNOWN' : 'SES_SANDBOX');
        $mode = $this->guard->mode();
        $add('EMAIL_SENDING_MODE', 'delivery', $mode === EmailSendingMode::Live, 'SENDING_MODE_'.strtoupper($mode->value));
        $add('DISPATCH', 'delivery', false, 'DISPATCH_NOT_AVAILABLE');

        $blocked = array_filter($checks, fn ($c) => $c['status'] === 'BLOCKED');

        return [
            'can_prepare' => array_filter($blocked, fn ($c) => $c['group'] === 'preparation') === [],
            'sendable' => $blocked === [],
            'reason_codes' => array_values(array_map(fn ($c) => (string) $c['reason'], $blocked)),
            'checks' => $checks,
            // Provenance only: consent is never frozen with the audience; it
            // is re-checked at dispatch and send time.
            'consent' => ['strategy' => $consent['strategy'], 'source' => $consent['source_label'],
                'last_reconciliation' => $consent['reconciliation']['last_applied']['period_end'] ?? null, 'reconciliation_age_hours' => $consent['reconciliation']['age_hours']],
        ];
    }
}
