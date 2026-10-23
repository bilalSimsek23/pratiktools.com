<?php

namespace App\Consent;

use App\Commerce\DataPreview;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shadow reconciliation and reporting for e-mail consent. Counts only;
 * samples are masked. Nothing here changes consent state except the
 * derived consent_conflicts list.
 *
 * İYS class of an address (individual e-mail):
 *   ONAY        bulk or single status says ONAY
 *   RET         RET known (changes, single status, push)
 *   NO_RECORD   single status found no record
 *   NOT_ONAY    not in the bulk ONAY list, not resolved yet (RET or no record)
 *   NOT_QUERIED never asked (İYS not connected, or address added since)
 */
final class ConsentReports
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SendEligibility $eligibility,
    ) {}

    /**
     * Per e-mail recipient: store flag, newsletter flag, İYS class, links.
     */
    private function baseSql(): string
    {
        return <<<'SQL'
            SELECT r.id, r.is_valid, r.is_marketplace,
                t.status AS store, n.status AS newsletter,
                t.consent_date AS store_date, i.consent_date AS iys_date,
                CASE
                    WHEN i.status = 'GRANTED' THEN 'ONAY'
                    WHEN i.status = 'REVOKED' THEN 'RET'
                    WHEN i.status = 'PENDING' THEN 'PENDING'
                    WHEN i.status = 'UNKNOWN' AND i.metadata->>'resolved' = 'no_record' THEN 'NO_RECORD'
                    WHEN i.status = 'UNKNOWN' THEN 'NOT_ONAY'
                    ELSE 'NOT_QUERIED'
                END AS iys,
                (SELECT count(*) FROM recipient_customers rc WHERE rc.tenant_id = r.tenant_id AND rc.recipient_id = r.id) AS customers,
                t.metadata->>'members_disagree' AS members_disagree
            FROM consent_recipients r
            LEFT JOIN consents t ON t.tenant_id = r.tenant_id AND t.recipient_id = r.id AND t.channel = 'EMAIL' AND t.recipient_type = 'INDIVIDUAL' AND t.source_provider = 'TICIMAX'
            LEFT JOIN consents n ON n.tenant_id = r.tenant_id AND n.recipient_id = r.id AND n.channel = 'EMAIL' AND n.recipient_type = 'INDIVIDUAL' AND n.source_provider = 'TICIMAX_NEWSLETTER'
            LEFT JOIN consents i ON i.tenant_id = r.tenant_id AND i.recipient_id = r.id AND i.channel = 'EMAIL' AND i.recipient_type = 'INDIVIDUAL' AND i.source_provider = 'IYS'
            WHERE r.tenant_id = ? AND r.address_type = 'email'
            SQL;
    }

    /**
     * Ticimax vs İYS matrix (groups A–H of the F2 brief).
     *
     * @return array<string, array{label: string, count: int, base: int, percentage: float}>
     */
    public function matrix(): array
    {
        $row = DB::selectOne(<<<SQL
            SELECT
                count(*) FILTER (WHERE store IS NOT NULL) AS store_base,
                count(*) FILTER (WHERE store = 'GRANTED' AND iys = 'ONAY') AS a,
                count(*) FILTER (WHERE store = 'GRANTED' AND iys = 'RET') AS b,
                count(*) FILTER (WHERE store = 'REVOKED' AND iys = 'ONAY') AS c,
                count(*) FILTER (WHERE store = 'REVOKED' AND iys = 'RET') AS d,
                count(*) FILTER (WHERE store IS NOT NULL AND iys = 'NO_RECORD') AS e_no_record,
                count(*) FILTER (WHERE store IS NOT NULL AND iys IN ('NOT_ONAY', 'NOT_QUERIED', 'PENDING')) AS e_unresolved,
                count(*) FILTER (WHERE iys IN ('ONAY', 'RET')) AS iys_base,
                count(*) FILTER (WHERE iys IN ('ONAY', 'RET') AND customers = 0) AS f,
                count(*) FILTER (WHERE newsletter IS NOT NULL) AS newsletter_base,
                count(*) FILTER (WHERE newsletter IS NOT NULL AND iys = 'RET') AS g,
                count(*) FILTER (WHERE newsletter IS NOT NULL AND iys = 'ONAY') AS h
            FROM ({$this->baseSql()}) x
            SQL, [$this->context->requireTenant()]);

        $cell = fn (string $label, int $count, int $base) => ['label' => $label, 'count' => $count, 'base' => $base, 'percentage' => $base > 0 ? round(100 * $count / $base, 2) : 0.0];

        return [
            'A' => $cell('Ticimax MailIzin TRUE / İYS ONAY', (int) $row->a, (int) $row->store_base),
            'B' => $cell('Ticimax MailIzin TRUE / İYS RET', (int) $row->b, (int) $row->store_base),
            'C' => $cell('Ticimax MailIzin FALSE / İYS ONAY', (int) $row->c, (int) $row->store_base),
            'D' => $cell('Ticimax MailIzin FALSE / İYS RET', (int) $row->d, (int) $row->store_base),
            'E' => $cell('Ticimax record / İYS no record (confirmed)', (int) $row->e_no_record, (int) $row->store_base),
            'E2' => $cell('Ticimax record / İYS not ONAY, RET or no record unresolved', (int) $row->e_unresolved, (int) $row->store_base),
            'F' => $cell('İYS record / no Ticimax customer', (int) $row->f, (int) $row->iys_base),
            'G' => $cell('Newsletter / İYS RET', (int) $row->g, (int) $row->newsletter_base),
            'H' => $cell('Newsletter / İYS ONAY', (int) $row->h, (int) $row->newsletter_base),
        ];
    }

    /**
     * Store (Ticimax member flag or newsletter, any "no" wins) vs İYS.
     *
     * @return array<string, int>
     */
    public function reconciliation(): array
    {
        return collect(DB::select(<<<SQL
            SELECT cls, count(*) AS n FROM (
                SELECT CASE
                    WHEN local IS NULL AND iys IN ('ONAY', 'RET') THEN 'MISSING_LOCAL'
                    WHEN local IS NULL THEN 'NO_DATA'
                    WHEN local = 'GRANTED' AND iys = 'ONAY' AND store_date IS NOT NULL AND iys_date IS NOT NULL AND abs(extract(epoch FROM store_date - iys_date)) > 86400 THEN 'DATE_MISMATCH'
                    WHEN local = 'GRANTED' AND iys = 'ONAY' THEN 'MATCHED'
                    WHEN local = 'REVOKED' AND iys IN ('RET', 'NO_RECORD', 'NOT_ONAY') THEN 'MATCHED'
                    WHEN local = 'GRANTED' AND iys = 'RET' THEN 'STATUS_MISMATCH'
                    WHEN local = 'REVOKED' AND iys = 'ONAY' THEN 'STATUS_MISMATCH'
                    WHEN local = 'GRANTED' AND iys IN ('NO_RECORD', 'NOT_ONAY') THEN 'MISSING_IYS'
                    ELSE 'NOT_QUERIED'
                END AS cls
                FROM (
                    SELECT x.*, CASE
                        WHEN 'REVOKED' IN (store, newsletter) THEN 'REVOKED'
                        WHEN 'GRANTED' IN (store, newsletter) THEN 'GRANTED'
                    END AS local
                    FROM ({$this->baseSql()}) x
                ) y
            ) z GROUP BY cls ORDER BY cls
            SQL, [$this->context->requireTenant()]))->mapWithKeys(fn ($r) => [$r->cls => (int) $r->n])->all();
    }

    /**
     * Rebuilds the open conflict list from the current state; conflicts
     * that no longer hold are resolved, not deleted.
     *
     * @return array<string, int>
     */
    public function refreshConflicts(): array
    {
        $tenantId = $this->context->requireTenant();
        $kinds = [
            'STORE_GRANTED_IYS_REVOKED' => "store = 'GRANTED' AND iys = 'RET'",
            'STORE_REVOKED_IYS_GRANTED' => "store = 'REVOKED' AND iys = 'ONAY'",
            'NEWSLETTER_IYS_REVOKED' => "newsletter = 'GRANTED' AND iys = 'RET'",
            'SHARED_ADDRESS' => 'customers > 1',
            'STORE_MEMBERS_DISAGREE' => "members_disagree = 'true'",
        ];

        $counts = [];
        DB::transaction(function () use ($tenantId, $kinds, &$counts) {
            foreach ($kinds as $kind => $condition) {
                DB::statement(<<<SQL
                    INSERT INTO consent_conflicts (id, tenant_id, recipient_id, channel, kind, status, details, detected_at)
                    SELECT gen_random_uuid(), ?, x.id, 'EMAIL', ?, 'open',
                        jsonb_build_object('store', x.store, 'newsletter', x.newsletter, 'iys', x.iys, 'customers', x.customers, 'store_date', x.store_date, 'iys_date', x.iys_date), now()
                    FROM ({$this->baseSql()}) x WHERE {$condition}
                    ON CONFLICT (tenant_id, recipient_id, channel, kind) DO UPDATE SET
                        status = 'open', details = EXCLUDED.details, resolved_at = NULL
                    SQL, [$tenantId, $kind, $tenantId]);

                DB::update(<<<SQL
                    UPDATE consent_conflicts cc SET status = 'resolved', resolved_at = now()
                    WHERE cc.tenant_id = ? AND cc.kind = ? AND cc.status = 'open'
                      AND cc.recipient_id NOT IN (SELECT x.id FROM ({$this->baseSql()}) x WHERE {$condition})
                    SQL, [$tenantId, $kind, $tenantId]);

                $counts[$kind] = DB::table('consent_conflicts')->where('tenant_id', $tenantId)->where('kind', $kind)->where('status', 'open')->count();
            }
        });

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function newsletter(): array
    {
        $row = DB::selectOne(<<<SQL
            SELECT count(*) AS total,
                count(*) FILTER (WHERE iys = 'ONAY') AS iys_onay,
                count(*) FILTER (WHERE iys = 'RET') AS iys_ret,
                count(*) FILTER (WHERE iys NOT IN ('ONAY', 'RET')) AS iys_unknown,
                count(*) FILTER (WHERE store = 'GRANTED') AS ticimax_true,
                count(*) FILTER (WHERE store = 'REVOKED') AS ticimax_false,
                count(*) FILTER (WHERE store IS NULL) AS not_a_member,
                count(*) FILTER (WHERE newsletter = 'GRANTED') AS newsletter_opt_in
            FROM ({$this->baseSql()}) x WHERE newsletter IS NOT NULL
            SQL, [$this->context->requireTenant()]);

        return array_map('intval', (array) $row);
    }

    /**
     * The F2 business table.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $row = DB::selectOne(<<<SQL
            SELECT count(*) AS total_unique_email_recipients,
                count(*) FILTER (WHERE store = 'GRANTED') AS ticimax_mailizin_true,
                count(*) FILTER (WHERE store = 'REVOKED') AS ticimax_mailizin_false,
                count(*) FILTER (WHERE newsletter IS NOT NULL) AS newsletter_total,
                count(*) FILTER (WHERE iys = 'ONAY') AS iys_onay,
                count(*) FILTER (WHERE iys = 'RET') AS iys_ret,
                count(*) FILTER (WHERE iys NOT IN ('ONAY', 'RET')) AS iys_unknown,
                count(*) FILTER (WHERE iys = 'NOT_QUERIED') AS iys_not_queried,
                count(*) FILTER (WHERE store = 'GRANTED' AND iys = 'RET') AS ticimax_true_iys_ret,
                count(*) FILTER (WHERE store = 'REVOKED' AND iys = 'ONAY') AS ticimax_false_iys_onay,
                count(*) FILTER (WHERE newsletter IS NOT NULL AND iys = 'RET') AS newsletter_iys_ret,
                count(*) FILTER (WHERE customers > 1) AS identity_conflict,
                count(*) FILTER (WHERE NOT is_valid) AS invalid_email,
                count(*) FILTER (WHERE is_marketplace) AS marketplace_address
            FROM ({$this->baseSql()}) x
            SQL, [$this->context->requireTenant()]);

        $out = array_map('intval', (array) $row);
        $out['final_sendable_email_count'] = $this->eligibility->reasonCounts()[EligibilityReason::Allowed->value] ?? 0;

        return $out;
    }

    /**
     * "If we mailed every active IGS customer today": candidates are the
     * e-mail addresses of active customers (members not deactivated,
     * guests, newsletter subscribers).
     *
     * @return array<string, mixed>
     */
    public function shadowAudience(): array
    {
        $tenantId = $this->context->requireTenant();
        $candidates = "SELECT rc.recipient_id FROM recipient_customers rc JOIN customers c ON c.id = rc.customer_id AND c.tenant_id = rc.tenant_id
                       JOIN consent_recipients r2 ON r2.id = rc.recipient_id AND r2.tenant_id = rc.tenant_id AND r2.address_type = 'email'
                       WHERE rc.tenant_id = ? AND c.is_active IS DISTINCT FROM false";
        $reasons = $this->eligibility->reasonCounts($candidates, [$tenantId]);
        $total = array_sum($reasons);
        $customers = (int) DB::selectOne('SELECT count(*) AS n FROM customers WHERE tenant_id = ? AND is_active IS DISTINCT FROM false', [$tenantId])->n;
        $withEmail = (int) DB::selectOne("SELECT count(DISTINCT rc.customer_id) AS n FROM recipient_customers rc JOIN consent_recipients r ON r.id = rc.recipient_id AND r.tenant_id = rc.tenant_id
                                          JOIN customers c ON c.id = rc.customer_id AND c.tenant_id = rc.tenant_id
                                          WHERE rc.tenant_id = ? AND r.address_type = 'email' AND c.is_active IS DISTINCT FROM false", [$tenantId])->n;
        $iys = DB::selectOne("SELECT count(*) FILTER (WHERE i.status = 'GRANTED') AS onay, count(*) FILTER (WHERE i.status = 'REVOKED') AS ret
                              FROM consents i WHERE i.tenant_id = ? AND i.channel = 'EMAIL' AND i.recipient_type = 'INDIVIDUAL' AND i.source_provider = 'IYS'
                                AND i.recipient_id IN ({$candidates})", [$tenantId, $tenantId]);

        return [
            'segment' => 'All active customers with an e-mail address',
            'active_customers' => $customers,
            'customers_with_email' => $withEmail,
            'total_candidates' => $total,
            'valid_emails' => $total - ($reasons['INVALID_EMAIL'] ?? 0),
            'iys_onay' => (int) $iys->onay,
            'iys_ret' => (int) $iys->ret,
            'unknown' => ($reasons['IYS_UNKNOWN'] ?? 0) + ($reasons['IYS_UNAVAILABLE'] ?? 0) + ($reasons['IYS_PENDING'] ?? 0) + ($reasons['IYS_STALE'] ?? 0),
            'suppressed' => $reasons['LOCAL_SUPPRESSED'] ?? 0,
            'identity_conflict' => $reasons['IDENTITY_CONFLICT'] ?? 0,
            'final_sendable' => $reasons['ALLOWED'] ?? 0,
            'exclusions_by_reason' => $reasons,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $reasons = $this->eligibility->reasonCounts();
        $summary = $this->summary();

        return [
            'total_recipients' => $summary['total_unique_email_recipients'],
            'onay' => $summary['iys_onay'],
            'ret' => $summary['iys_ret'],
            'unknown' => $summary['iys_unknown'],
            'conflict' => (int) DB::table('consent_conflicts')->where('tenant_id', $this->context->requireTenant())->where('status', 'open')->distinct()->count('recipient_id'),
            'sendable' => $reasons['ALLOWED'] ?? 0,
            'iys_available' => $this->eligibility->iysAvailable(),
            'reasons' => $reasons,
        ];
    }

    /**
     * Masked conflict samples for review screens.
     *
     * @return list<array<string, mixed>>
     */
    public function conflicts(?string $kind = null, int $limit = 25): array
    {
        return DB::table('consent_conflicts as cc')
            ->join('consent_recipients as r', fn ($j) => $j->on('r.id', '=', 'cc.recipient_id')->on('r.tenant_id', '=', 'cc.tenant_id'))
            ->where('cc.tenant_id', $this->context->requireTenant())
            ->where('cc.status', 'open')
            ->when($kind !== null, fn ($q) => $q->where('cc.kind', $kind))
            ->orderByDesc('cc.detected_at')
            ->limit(min($limit, 100))
            ->get(['cc.id', 'cc.kind', 'cc.details', 'cc.detected_at', 'r.address'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'recipient' => DataPreview::maskEmail($c->address),
                'reason' => $c->kind,
                'sources' => json_decode($c->details, true),
                'detected_at' => $c->detected_at,
            ])->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function snapshot(string $kind, array $payload): void
    {
        DB::table('consent_reports')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->context->requireTenant(),
            'kind' => $kind,
            'payload' => json_encode($payload),
            'generated_at' => now(),
        ]);
    }
}
