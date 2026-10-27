<?php

namespace App\Consent;

use App\Consent\Data\EligibilityDecision;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * "May we send this marketing message?" — computed, never acted on in F2.
 *
 * The official İYS state of an address is the latest observation (by its
 * "as of" time) of the official sources: the İYS API, a verified ViA event
 * or an applied İYS change report. On a tie RET wins. Local suppressions
 * (unsubscribe, bounce, complaint, manual) are never overridden by it.
 *
 * E-mail rules, first match wins (doubt means no):
 *  1. invalid address                          INVALID_EMAIL
 *  2. marketplace relay address                MARKETPLACE_IDENTITY
 *  3. active local suppression                 LOCAL_SUPPRESSED
 *  4. official RET (individual or merchant)    IYS_REVOKED      (RET always wins)
 *  5. the tenant's consent strategy cannot
 *     back a decision (ConsentStrategy)        IYS_UNAVAILABLE
 *  6. via_reconciliation: last applied İYS
 *     change report older than the policy      IYS_RECONCILIATION_STALE
 *  7. no official answer, ViA still pending    IYS_PENDING
 *  8. only a merchant (TACIR) ONAY             MERCHANT_POLICY_REVIEW
 *  9. no official answer / not in ONAY         IYS_UNKNOWN
 * 10. official pending                         IYS_PENDING
 * 11. direct_api: ONAY older than the
 *     freshness limit                          IYS_STALE
 * 12. explicit opt-out at the store after the
 *     official consent date (policy, on)       SOURCE_REVOKED
 * 13. address shared by several customers      IDENTITY_CONFLICT
 * 14. otherwise                                ALLOWED
 *
 * One SQL expression serves single decisions and whole audiences, so both
 * always agree, and every caller (audience, automation, dispatch guard)
 * re-evaluates it at decision time. SMS and call are modelled but not
 * evaluated yet; push permission will come from the device subscription,
 * never from İYS.
 */
final class SendEligibility
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ConsentStrategy $strategy,
    ) {}

    public function forCustomer(string $customerId, ConsentChannel $channel = ConsentChannel::Email): EligibilityDecision
    {
        if ($channel !== ConsentChannel::Email) {
            return new EligibilityDecision($channel, EligibilityReason::ChannelNotSupported);
        }

        $recipientId = DB::table('recipient_customers as rc')
            ->join('consent_recipients as r', fn ($j) => $j->on('r.id', '=', 'rc.recipient_id')->on('r.tenant_id', '=', 'rc.tenant_id'))
            ->where('rc.tenant_id', $this->context->requireTenant())
            ->where('rc.customer_id', $customerId)
            ->where('r.address_type', 'email')
            ->value('r.id');

        return $recipientId === null
            ? new EligibilityDecision($channel, EligibilityReason::NoRecipient)
            : $this->forRecipient((string) $recipientId, $channel);
    }

    public function forRecipient(string $recipientId, ConsentChannel $channel = ConsentChannel::Email): EligibilityDecision
    {
        if ($channel !== ConsentChannel::Email) {
            return new EligibilityDecision($channel, EligibilityReason::ChannelNotSupported, $recipientId);
        }

        $row = DB::selectOne($this->sql('AND r.id = ?'), [...$this->bindings(), $recipientId]);
        if ($row === null) {
            return new EligibilityDecision($channel, EligibilityReason::NoRecipient, $recipientId);
        }

        return new EligibilityDecision($channel, EligibilityReason::from($row->reason), $recipientId, [
            'iys_individual' => $row->iys_i,
            'iys_merchant' => $row->iys_m,
            'store_revoked_at' => $row->store_revoked_at,
            'linked_customers' => (int) $row->customers,
        ]);
    }

    /**
     * Reason per e-mail recipient for a set of recipients (subquery
     * returning recipient ids) or for all.
     *
     * @param  array<int, mixed>  $filterBindings
     * @return array<string, int> reason => count
     */
    public function reasonCounts(?string $recipientFilterSql = null, array $filterBindings = []): array
    {
        $filter = $recipientFilterSql === null ? '' : "AND r.id IN ({$recipientFilterSql})";

        return collect(DB::select("SELECT reason, count(*) AS n FROM ({$this->sql($filter)}) e GROUP BY reason", [...$this->bindings(), ...$filterBindings]))
            ->mapWithKeys(fn ($r) => [$r->reason => (int) $r->n])
            ->all();
    }

    /**
     * The decision query; $filter narrows consent_recipients r.
     */
    public function sql(string $filter = ''): string
    {
        $official = "'".implode("', '", ConsentSourceProvider::OFFICIAL)."'";
        $latest = fn (string $type) => "LEFT JOIN LATERAL (
                    SELECT c.status, c.last_verified_at, c.consent_date FROM consents c
                    WHERE c.tenant_id = r.tenant_id AND c.recipient_id = r.id AND c.channel = 'EMAIL' AND c.recipient_type = '{$type}' AND c.source_provider IN ({$official})
                    ORDER BY (c.metadata->>'as_of')::timestamptz DESC NULLS LAST, (c.status = 'REVOKED') DESC LIMIT 1
                )";

        return <<<SQL
            SELECT b.*,
                CASE
                    WHEN NOT b.is_valid THEN 'INVALID_EMAIL'
                    WHEN b.is_marketplace THEN 'MARKETPLACE_IDENTITY'
                    WHEN b.suppressed THEN 'LOCAL_SUPPRESSED'
                    WHEN b.iys_i = 'REVOKED' OR b.iys_m = 'REVOKED' THEN 'IYS_REVOKED'
                    WHEN NOT ?::boolean THEN 'IYS_UNAVAILABLE'
                    WHEN ?::boolean THEN 'IYS_RECONCILIATION_STALE'
                    WHEN (b.iys_i IS NULL OR b.iys_i = 'UNKNOWN') AND b.via_pending THEN 'IYS_PENDING'
                    WHEN (b.iys_i IS NULL OR b.iys_i = 'UNKNOWN') AND b.iys_m = 'GRANTED' THEN 'MERCHANT_POLICY_REVIEW'
                    WHEN b.iys_i IS NULL OR b.iys_i = 'UNKNOWN' THEN 'IYS_UNKNOWN'
                    WHEN b.iys_i = 'PENDING' THEN 'IYS_PENDING'
                    WHEN ?::boolean AND (b.iys_i_verified IS NULL OR b.iys_i_verified < ?::timestamptz) THEN 'IYS_STALE'
                    WHEN ?::boolean AND b.store_revoked_at IS NOT NULL AND (b.iys_i_date IS NULL OR b.store_revoked_at > b.iys_i_date) THEN 'SOURCE_REVOKED'
                    WHEN b.customers > 1 THEN 'IDENTITY_CONFLICT'
                    ELSE 'ALLOWED'
                END AS reason
            FROM (
                SELECT r.id, r.is_valid, r.is_marketplace,
                    (SELECT count(*) FROM recipient_customers rc WHERE rc.tenant_id = r.tenant_id AND rc.recipient_id = r.id) AS customers,
                    EXISTS (SELECT 1 FROM suppressions s WHERE s.tenant_id = r.tenant_id AND s.recipient_id = r.id AND s.lifted_at IS NULL AND (s.channel IS NULL OR s.channel = 'EMAIL')) AS suppressed,
                    ii.status AS iys_i, ii.last_verified_at AS iys_i_verified, ii.consent_date AS iys_i_date, im.status AS iys_m,
                    EXISTS (SELECT 1 FROM via_consent_requests v WHERE v.tenant_id = r.tenant_id AND v.recipient_id = r.id AND v.channel = 'EMAIL' AND v.state = 'pending') AS via_pending,
                    (SELECT max((c.metadata->>'as_of')::timestamptz) FROM consents c
                       WHERE c.tenant_id = r.tenant_id AND c.recipient_id = r.id AND c.channel = 'EMAIL' AND c.source_provider NOT IN ({$official})
                         AND c.status = 'REVOKED' AND jsonb_exists(c.metadata, 'explicit_change')) AS store_revoked_at
                FROM consent_recipients r
                {$latest('INDIVIDUAL')} ii ON true
                {$latest('MERCHANT')} im ON true
                WHERE r.tenant_id = ? AND r.address_type = 'email' {$filter}
            ) b
            SQL;
    }

    /**
     * @return list<mixed>
     */
    public function bindings(): array
    {
        [$operational, $stale, $rowFreshness] = $this->strategy->eligibilityFlags();

        return [
            $operational,
            $stale,
            $rowFreshness,
            now()->subHours((int) config('consent.eligibility.iys_max_age_hours'))->toIso8601String(),
            (bool) config('consent.eligibility.store_opt_out_blocks'),
            $this->context->requireTenant(),
        ];
    }

    /**
     * İYS counts as available once a full status sync has succeeded for the
     * tenant's consent integration (direct_api strategy).
     */
    public function iysAvailable(): bool
    {
        return $this->strategy->directApiAvailable();
    }
}
