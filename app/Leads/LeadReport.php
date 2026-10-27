<?php

namespace App\Leads;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Aggregate lead capture report (no addresses): submissions, outcomes,
 * consent, pending İYS intents and source breakdown.
 */
final class LeadReport
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array<string, mixed>
     */
    public function report(int $days = 30, ?string $formId = null): array
    {
        $tenantId = $this->context->requireTenant();
        $since = now()->subDays($days);
        $scope = fn ($q) => $formId === null ? $q : $q->where('form_id', $formId);
        $m = $scope(DB::table('lead_metrics_daily')->where('tenant_id', $tenantId)->where('day', '>=', $since->toDateString()))
            ->selectRaw(implode(', ', array_map(fn ($c) => "coalesce(sum({$c}), 0) AS {$c}", LeadMetrics::COUNTERS)))->first();
        $s = $scope(DB::table('lead_submissions')->where('tenant_id', $tenantId)->where('received_at', '>=', $since));

        return [
            'days' => $days,
            'counters' => array_map('intval', (array) $m),
            'stored_submissions' => (clone $s)->count(),
            'unique_emails' => (clone $s)->distinct()->count('email_hash'),
            'new_recipients' => (clone $s)->where('new_recipient', true)->count(),
            'existing_recipients' => (clone $s)->where('new_recipient', false)->count(),
            'outcomes' => (clone $s)->selectRaw('outcome, count(*) AS n')->groupBy('outcome')->pluck('n', 'outcome')->map(fn ($n) => (int) $n)->all(),
            'consent_checked' => (clone $s)->where('consent_checked', true)->count(),
            'consent_unchecked' => (clone $s)->where('consent_checked', false)->count(),
            'double_opt_in' => (clone $s)->selectRaw('opt_in_status, count(*) AS n')->groupBy('opt_in_status')->pluck('n', 'opt_in_status')->map(fn ($n) => (int) $n)->all(),
            'pending_iys_onay' => DB::table('consent_write_intents')->where('tenant_id', $tenantId)->where('source', 'LEAD_FORM')->where('state', 'pending_write_disabled')->count(),
            'via_pending' => $scope(DB::table('via_consent_requests')->where('tenant_id', $tenantId)->where('state', 'pending'))->count(),
            'via_succeeded' => $scope(DB::table('via_consent_requests')->where('tenant_id', $tenantId)->where('state', 'succeeded'))->count(),
            'via_failed' => $scope(DB::table('via_consent_requests')->where('tenant_id', $tenantId)->whereIn('state', ['failed', 'expired']))->count(),
            'by_placement' => (clone $s)->selectRaw('placement, count(*) AS n')->groupBy('placement')->pluck('n', 'placement')->map(fn ($n) => (int) $n)->all(),
            'by_utm_source' => (clone $s)->whereNotNull('utm_source')->selectRaw('utm_source, count(*) AS n')->groupBy('utm_source')->orderByDesc('n')->limit(20)->pluck('n', 'utm_source')->map(fn ($n) => (int) $n)->all(),
        ];
    }
}
