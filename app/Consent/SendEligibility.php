<?php

namespace App\Consent;

use App\Consent\Data\EligibilityDecision;
use App\Consent\Sync\ConsentSynchronizer;
use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * "May we send this marketing message?" — computed, never acted on in F2.
 *
 * E-mail rules, first match wins (doubt means no):
 *  1. invalid address                          INVALID_EMAIL
 *  2. marketplace relay address                MARKETPLACE_IDENTITY
 *  3. active local suppression                 LOCAL_SUPPRESSED
 *  4. İYS RET (individual or merchant)         IYS_REVOKED      (RET always wins)
 *  5. no successful İYS sync for the tenant    IYS_UNAVAILABLE
 *  6. only a merchant (TACIR) ONAY             MERCHANT_POLICY_REVIEW
 *  7. no İYS answer / not in ONAY              IYS_UNKNOWN
 *  8. İYS pending                              IYS_PENDING
 *  9. İYS ONAY older than the freshness limit  IYS_STALE
 * 10. explicit opt-out at the store after the
 *     İYS consent date (policy, default on)    SOURCE_REVOKED
 * 11. address shared by several customers      IDENTITY_CONFLICT
 * 12. otherwise                                ALLOWED
 *
 * One SQL expression serves single decisions and whole audiences, so both
 * always agree. SMS and call are modelled but not evaluated yet; push
 * permission will come from the device subscription, never from İYS.
 */
final class SendEligibility
{
    public function __construct(private readonly TenantContext $context) {}

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
        return <<<SQL
            SELECT b.*,
                CASE
                    WHEN NOT b.is_valid THEN 'INVALID_EMAIL'
                    WHEN b.is_marketplace THEN 'MARKETPLACE_IDENTITY'
                    WHEN b.suppressed THEN 'LOCAL_SUPPRESSED'
                    WHEN b.iys_i = 'REVOKED' OR b.iys_m = 'REVOKED' THEN 'IYS_REVOKED'
                    WHEN NOT ?::boolean THEN 'IYS_UNAVAILABLE'
                    WHEN (b.iys_i IS NULL OR b.iys_i = 'UNKNOWN') AND b.iys_m = 'GRANTED' THEN 'MERCHANT_POLICY_REVIEW'
                    WHEN b.iys_i IS NULL OR b.iys_i = 'UNKNOWN' THEN 'IYS_UNKNOWN'
                    WHEN b.iys_i = 'PENDING' THEN 'IYS_PENDING'
                    WHEN b.iys_i_verified IS NULL OR b.iys_i_verified < ?::timestamptz THEN 'IYS_STALE'
                    WHEN ?::boolean AND b.store_revoked_at IS NOT NULL AND (b.iys_i_date IS NULL OR b.store_revoked_at > b.iys_i_date) THEN 'SOURCE_REVOKED'
                    WHEN b.customers > 1 THEN 'IDENTITY_CONFLICT'
                    ELSE 'ALLOWED'
                END AS reason
            FROM (
                SELECT r.id, r.is_valid, r.is_marketplace,
                    (SELECT count(*) FROM recipient_customers rc WHERE rc.tenant_id = r.tenant_id AND rc.recipient_id = r.id) AS customers,
                    EXISTS (SELECT 1 FROM suppressions s WHERE s.tenant_id = r.tenant_id AND s.recipient_id = r.id AND s.lifted_at IS NULL AND (s.channel IS NULL OR s.channel = 'EMAIL')) AS suppressed,
                    ii.status AS iys_i, ii.last_verified_at AS iys_i_verified, ii.consent_date AS iys_i_date, im.status AS iys_m,
                    (SELECT max((c.metadata->>'as_of')::timestamptz) FROM consents c
                       WHERE c.tenant_id = r.tenant_id AND c.recipient_id = r.id AND c.channel = 'EMAIL' AND c.source_provider <> 'IYS'
                         AND c.status = 'REVOKED' AND jsonb_exists(c.metadata, 'explicit_change')) AS store_revoked_at
                FROM consent_recipients r
                LEFT JOIN consents ii ON ii.tenant_id = r.tenant_id AND ii.recipient_id = r.id AND ii.channel = 'EMAIL' AND ii.recipient_type = 'INDIVIDUAL' AND ii.source_provider = 'IYS'
                LEFT JOIN consents im ON im.tenant_id = r.tenant_id AND im.recipient_id = r.id AND im.channel = 'EMAIL' AND im.recipient_type = 'MERCHANT' AND im.source_provider = 'IYS'
                WHERE r.tenant_id = ? AND r.address_type = 'email' {$filter}
            ) b
            SQL;
    }

    /**
     * @return list<mixed>
     */
    public function bindings(): array
    {
        return [
            $this->iysAvailable(),
            now()->subHours((int) config('consent.eligibility.iys_max_age_hours'))->toIso8601String(),
            (bool) config('consent.eligibility.store_opt_out_blocks'),
            $this->context->requireTenant(),
        ];
    }

    /**
     * İYS counts as available once a full status sync has succeeded for the
     * tenant's consent integration.
     */
    public function iysAvailable(): bool
    {
        $integration = TenantIntegration::query()->where('provider', 'iys')->where('capability', 'consent')->first();

        return $integration !== null && DB::table('integration_sync_cursors')
            ->where('integration_id', $integration->id)
            ->where('entity', ConsentSynchronizer::ENTITY_IYS_STATUS)
            ->whereNotNull('last_success_at')
            ->exists();
    }
}
