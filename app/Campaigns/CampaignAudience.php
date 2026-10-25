<?php

namespace App\Campaigns;

use App\Consent\SendEligibility;
use App\Messaging\FrequencyCap;
use App\Segments\ReadOnlyTransaction;
use App\Segments\SegmentCompiler;
use App\Segments\Segments;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Who a campaign would reach and why not, computed in the database (never
 * loaded into PHP) inside a READ ONLY transaction. Counts only.
 *
 * Being in the audience is not permission: the guard re-checks every
 * recipient at send time.
 */
final class CampaignAudience
{
    public const IDENTITY = ['INVALID_EMAIL', 'MARKETPLACE_IDENTITY', 'IDENTITY_CONFLICT'];

    public const UNKNOWN = ['IYS_UNKNOWN', 'IYS_STALE', 'IYS_UNAVAILABLE', 'IYS_PENDING'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly SegmentCompiler $compiler,
        private readonly Segments $segments,
        private readonly SendEligibility $eligibility,
    ) {}

    /**
     * Customer ids of the campaign audience: the frozen snapshot, else the
     * live segment.
     *
     * @return array{sql: string, bindings: list<mixed>, frozen: bool}
     */
    public function customers(object $campaign): array
    {
        $tenantId = $this->context->requireTenant();
        if ($campaign->segment_snapshot_id !== null) {
            return ['sql' => 'SELECT m.customer_id FROM segment_snapshot_members m WHERE m.tenant_id = ? AND m.snapshot_id = ?', 'bindings' => [$tenantId, $campaign->segment_snapshot_id], 'frozen' => true];
        }
        if ($campaign->segment_id === null) {
            throw new CampaignError('AUDIENCE_MISSING', 'The campaign has no audience.');
        }
        $q = $this->compiler->select($this->segments->definition($campaign->segment_id), $tenantId);

        return ['sql' => $q['sql'], 'bindings' => $q['bindings'], 'frozen' => false];
    }

    /**
     * @return array<string, mixed>
     */
    public function report(object $campaign): array
    {
        return ReadOnlyTransaction::run(fn () => $this->compute($campaign));
    }

    /**
     * @return array<string, mixed>
     */
    public function compute(object $campaign): array
    {
        $tenantId = $this->context->requireTenant();
        $started = hrtime(true);
        $aud = $this->customers($campaign);

        $raw = (int) DB::selectOne("SELECT count(*) AS n FROM ({$aud['sql']}) a", $aud['bindings'])->n;
        $recipientFilter = "SELECT rc.recipient_id FROM recipient_customers rc WHERE rc.tenant_id = ? AND rc.customer_id IN ({$aud['sql']})";
        $withEmail = (int) DB::selectOne("SELECT count(DISTINCT rc.customer_id) AS n FROM recipient_customers rc
            JOIN consent_recipients r ON r.tenant_id = rc.tenant_id AND r.id = rc.recipient_id AND r.address_type = 'email'
            WHERE rc.tenant_id = ? AND rc.customer_id IN ({$aud['sql']})", [$tenantId, ...$aud['bindings']])->n;
        $reasons = $this->eligibility->reasonCounts($recipientFilter, [$tenantId, ...$aud['bindings']]);
        ksort($reasons);
        $sum = fn (array $codes) => array_sum(array_intersect_key($reasons, array_flip($codes)));
        $allowed = (int) ($reasons['ALLOWED'] ?? 0);
        $capped = $allowed === 0 ? 0 : $this->capped("SELECT e.id FROM ({$this->eligibility->sql("AND r.id IN ({$recipientFilter})")}) e WHERE e.reason = 'ALLOWED'",
            [...$this->eligibility->bindings(), $tenantId, ...$aud['bindings']]);
        $recipients = array_sum($reasons);
        $iysAvailable = $this->eligibility->iysAvailable();

        return [
            'audience_frozen' => $aud['frozen'],
            'raw_audience' => $raw,
            'customers_without_email' => $raw - $withEmail,
            'email_recipients' => $recipients,
            'valid_identity' => $recipients - $sum(self::IDENTITY),
            'invalid_identity' => $sum(['INVALID_EMAIL', 'IDENTITY_CONFLICT']),
            'marketplace_excluded' => (int) ($reasons['MARKETPLACE_IDENTITY'] ?? 0),
            'suppressed' => (int) ($reasons['LOCAL_SUPPRESSED'] ?? 0),
            'consent_blocked' => $recipients - $allowed - $sum(self::IDENTITY) - (int) ($reasons['LOCAL_SUPPRESSED'] ?? 0),
            'iys_unknown_or_stale' => $sum(self::UNKNOWN),
            'consent_allowed' => $allowed,
            'frequency_capped' => $capped,
            'currently_eligible' => $allowed - $capped,
            'reasons' => $reasons,
            'explanation' => $iysAvailable ? null : 'Marketing sending is blocked because IYS consent could not be verified.',
            'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
        ];
    }

    /**
     * Recipients (of the given id set) already at the marketing e-mail cap,
     * counted in one statement (same rule as FrequencyCap).
     *
     * @param  list<mixed>  $bindings
     */
    private function capped(string $recipientIdsSql, array $bindings): int
    {
        $limits = FrequencyCap::limits('email');
        $tenantId = $this->context->requireTenant();

        return (int) DB::selectOne(
            "WITH ids AS ({$recipientIdsSql}),
                  hits AS (
                    SELECT m.recipient_id AS id, m.sent_at AS at FROM email_messages m WHERE m.tenant_id = ? AND m.message_type = 'marketing' AND m.status = 'sent'
                        AND m.sent_at >= now() - interval '7 days' AND m.recipient_id IN (SELECT id FROM ids)
                    UNION ALL
                    SELECT p.recipient_ref, p.created_at FROM automation_planned_actions p WHERE p.tenant_id = ? AND p.channel = 'email' AND p.message_type = 'marketing'
                        AND p.status = 'planned' AND p.created_at >= now() - interval '7 days' AND p.recipient_ref IN (SELECT id FROM ids))
             SELECT count(*) AS n FROM (SELECT id FROM hits GROUP BY id
                HAVING count(*) FILTER (WHERE at >= now() - interval '1 day') >= ? OR count(*) >= ?) c",
            [...$bindings, $tenantId, $tenantId, $limits['per_day'], $limits['per_week']],
        )->n;
    }
}
