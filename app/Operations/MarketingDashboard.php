<?php

namespace App\Operations;

use App\Commerce\Sync\CommerceRelink;
use App\Consent\SendEligibility;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Marketing dashboard: aggregates only (no personal data), indexed counts;
 * the expensive consent breakdown is cached for ten minutes per tenant.
 */
final class MarketingDashboard
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SendEligibility $eligibility,
        private readonly IntegrationStatus $integrations,
        private readonly ExternalBlockers $blockers,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $tenantId = $this->context->requireTenant();
        $since = now()->subDays(30);
        $campaigns = DB::table('campaigns')->where('tenant_id', $tenantId)->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $status = $this->integrations->all();
        $blockers = $this->blockers->all($status);
        $externallyBlocked = collect($blockers)->contains(fn ($b) => $b['status'] !== 'PASS');
        $active = ($campaigns['draft'] ?? 0) + ($campaigns['ready'] ?? 0) + ($campaigns['scheduled'] ?? 0) + ($campaigns['paused'] ?? 0);
        $leads = DB::table('lead_metrics_daily')->where('tenant_id', $tenantId)->where('day', '>=', $since->toDateString())
            ->selectRaw('coalesce(sum(submissions), 0) AS submissions, coalesce(sum(accepted), 0) AS accepted')->first();
        $planned = DB::table('automation_planned_actions')->where('tenant_id', $tenantId)->where('status', 'planned')->where('created_at', '>=', $since)
            ->selectRaw('channel, count(*) AS n')->groupBy('channel')->pluck('n', 'channel');
        $consent = Cache::remember("dashboard:consent:{$tenantId}", 600, fn () => $this->eligibility->reasonCounts());

        return [
            'campaigns' => [
                'active' => $active, 'draft' => $campaigns['draft'] ?? 0, 'ready' => $campaigns['ready'] ?? 0, 'scheduled' => $campaigns['scheduled'] ?? 0,
                'blocked' => $externallyBlocked ? $active : 0,
            ],
            'leads_30d' => ['submissions' => (int) $leads->submissions, 'accepted' => (int) $leads->accepted,
                'newsletter' => DB::table('customer_events')->where('tenant_id', $tenantId)->where('name', 'NEWSLETTER_SUBSCRIBED')->where('occurred_at', '>=', $since)->count()],
            'consent' => ['recipients' => array_sum($consent), 'allowed' => (int) ($consent['ALLOWED'] ?? 0), 'blocked' => array_sum($consent) - (int) ($consent['ALLOWED'] ?? 0)],
            'automations' => ['published' => DB::table('automations')->where('tenant_id', $tenantId)->where('status', 'published')->count(),
                'planned_email_30d' => (int) ($planned['email'] ?? 0), 'planned_push_30d' => (int) ($planned['push'] ?? 0)],
            'dispatches' => DB::table('campaign_dispatches')->where('tenant_id', $tenantId)->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
            'integrations' => $status,
            'blockers' => $blockers,
            'cart' => Cache::remember("dashboard:cart:{$tenantId}", 600, fn () => app(CommerceRelink::class)->report()),
        ];
    }
}
