<?php

namespace App\Http\Controllers\Panel;

use App\Approvals\ApprovalError;
use App\Approvals\CampaignApprovals;
use App\Campaigns\CampaignAudience;
use App\Campaigns\CampaignDryRun;
use App\Campaigns\CampaignError;
use App\Campaigns\CampaignReadiness;
use App\Campaigns\Campaigns;
use App\Campaigns\CampaignStatus;
use App\Dispatch\DispatchGuard;
use App\Email\Templates\EmailTemplates;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\PanelHelpers;
use App\Operations\ReadinessCatalog;
use App\Panel\PanelMessages;
use App\Tenancy\Permission;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Campaign workflow: details → audience → content → sender → preview →
 * readiness → approval → dispatch. Business rules live in the F6/F7
 * services; this controller only maps forms and pages.
 */
final class CampaignController extends Controller
{
    use PanelHelpers;

    public function index(): View
    {
        $this->authorizePanel(Permission::CampaignsView);
        $campaigns = DB::table('campaigns as c')->leftJoin('segments as s', fn ($j) => $j->on('s.id', '=', 'c.segment_id')->on('s.tenant_id', '=', 'c.tenant_id'))
            ->leftJoin('segment_snapshots as ss', fn ($j) => $j->on('ss.id', '=', 'c.segment_snapshot_id')->on('ss.tenant_id', '=', 'c.tenant_id'))
            ->leftJoin('email_template_versions as v', fn ($j) => $j->on('v.id', '=', 'c.template_version_id')->on('v.tenant_id', '=', 'c.tenant_id'))
            ->leftJoin('email_templates as t', fn ($j) => $j->on('t.id', '=', 'v.template_id')->on('t.tenant_id', '=', 'v.tenant_id'))
            ->leftJoin('email_sender_identities as se', fn ($j) => $j->on('se.id', '=', 'c.sender_identity_id')->on('se.tenant_id', '=', 'c.tenant_id'))
            ->orderByDesc('c.updated_at')
            ->get(['c.id', 'c.name', 'c.type', 'c.channel', 'c.status', 'c.updated_at', 'c.created_by', 'c.frozen_at', 's.name as segment', 'ss.member_count as snapshot_size',
                't.name as template', 'v.version as template_version', 'se.from_email as sender',
                DB::raw("(SELECT count(*) FROM campaign_approvals a WHERE a.tenant_id = c.tenant_id AND a.campaign_id = c.id AND a.status = 'approved') AS approvals")]);

        return view('panel.campaigns.index', ['campaigns' => $campaigns]);
    }

    public function create(): View
    {
        $this->authorizePanel(Permission::CampaignsManage);

        return view('panel.campaigns.form', $this->options() + ['campaign' => null]);
    }

    public function store(Request $request, Campaigns $campaigns): RedirectResponse
    {
        $this->authorizePanel(Permission::CampaignsManage);
        try {
            $id = $campaigns->create($this->input($request), $this->actor());
        } catch (CampaignError $e) {
            return $this->failed($e->getMessage());
        }

        return redirect()->route('panel.campaigns.show', $id)->with('status', 'Kampanya taslağı oluşturuldu.');
    }

    public function show(string $campaign, Campaigns $campaigns, CampaignReadiness $readiness, CampaignApprovals $approvals, CampaignAudience $audience, EmailTemplates $templates): View
    {
        $this->authorizePanel(Permission::CampaignsView);
        $c = $this->find($campaigns, $campaign);
        $report = rescue(fn () => $audience->report($c), null, false);
        $r = $readiness->evaluate($c, $report);
        $preview = $c->template_version_id === null ? null : rescue(fn () => $templates->render($templates->version($c->template_version_id), EmailTemplates::sampleValues($c->name)), null, false);
        $valid = $c->frozen_at !== null ? $approvals->valid($c) : collect();

        return view('panel.campaigns.show', [
            'c' => $c,
            'audience' => $report,
            'readiness' => $r,
            'rows' => ReadinessCatalog::rows($r),
            'approvals' => $approvals->history($c->id),
            'validApprovals' => $valid,
            'required' => (int) config('dispatch.required_approvals')['test_only'],
            'liveRequired' => (int) config('dispatch.required_approvals')['live'],
            'liveAvailable' => DispatchGuard::LIVE_AVAILABLE,
            'preview' => $preview,
            'segment' => $c->segment_id === null ? null : DB::table('segments')->where('id', $c->segment_id)->first(['name', 'definition']),
            'template' => $c->template_version_id === null ? null : DB::table('email_template_versions as v')->join('email_templates as t', 't.id', '=', 'v.template_id')
                ->where('v.id', $c->template_version_id)->first(['t.name', 'v.version', 'v.status', 'v.id', 'v.template_id']),
            'sender' => $c->sender_identity_id === null ? null : DB::table('email_sender_identities')->where('id', $c->sender_identity_id)->first(),
            'dispatches' => DB::table('campaign_dispatches')->where('campaign_id', $c->id)->orderByDesc('requested_at')->get(),
            'dryRun' => session('dry_run'),
            'people' => $this->people(),
            'me' => $this->userId(),
            'can' => [
                'manage' => $this->can(Permission::CampaignsManage), 'publish' => $this->can(Permission::CampaignsPublish),
                'approve' => $this->can(Permission::CampaignsApprove), 'dispatch' => $this->can(Permission::CampaignsTestDispatch),
            ],
        ]);
    }

    public function edit(string $campaign, Campaigns $campaigns): View
    {
        $this->authorizePanel(Permission::CampaignsManage);
        $c = $this->find($campaigns, $campaign);
        abort_unless($c->status === 'draft', 403, 'Only drafts can be edited.');

        return view('panel.campaigns.form', $this->options() + ['campaign' => $c]);
    }

    public function update(Request $request, string $campaign, Campaigns $campaigns): RedirectResponse
    {
        $this->authorizePanel(Permission::CampaignsManage);
        try {
            $campaigns->update($campaign, $this->input($request), $this->actor());
        } catch (CampaignError $e) {
            return $this->failed($e->getMessage());
        }

        return redirect()->route('panel.campaigns.show', $campaign)->with('status', 'Kampanya güncellendi.');
    }

    public function dryRun(string $campaign, Campaigns $campaigns, CampaignDryRun $dryRun): RedirectResponse
    {
        $this->authorizePanel(Permission::CampaignsManage);
        $this->find($campaigns, $campaign);
        $result = $dryRun->run($campaign, $this->actor());
        unset($result['readiness'], $result['reasons']);

        return back()->with('dry_run', $result)->with('status', 'Dry-run tamamlandı (hiçbir şey gönderilmedi).');
    }

    public function transition(Request $request, string $campaign, Campaigns $campaigns): RedirectResponse
    {
        $this->authorizePanel(Permission::CampaignsPublish);
        $data = $request->validate(['to' => 'required|in:ready,scheduled,paused,cancelled,archived', 'scheduled_at' => 'nullable|date']);
        try {
            $campaigns->transition($campaign, CampaignStatus::from($data['to']), $this->actor(), isset($data['scheduled_at']) ? CarbonImmutable::parse($data['scheduled_at']) : null);
        } catch (CampaignError $e) {
            return $this->failed($e->getMessage());
        }

        return $this->done('Kampanya durumu güncellendi.');
    }

    public function reopen(string $campaign, Campaigns $campaigns): RedirectResponse
    {
        $this->authorizePanel(Permission::CampaignsManage);
        try {
            $campaigns->reopen($campaign, $this->actor());
        } catch (CampaignError $e) {
            return $this->failed($e->getMessage());
        }

        return $this->done('Kampanya düzenleme için açıldı; önceki onaylar geçersiz.');
    }

    public function requestApproval(string $campaign, Campaigns $campaigns, CampaignApprovals $approvals): RedirectResponse
    {
        $this->authorizePanel(Permission::CampaignsManage);
        try {
            $approvals->request($this->find($campaigns, $campaign), $this->userId());
        } catch (ApprovalError $e) {
            return $this->failed(PanelMessages::for($e->reason, $e->getMessage()));
        }

        return $this->done('Onay istendi; onaylayıcılara bildirim gönderildi (uygulama içi).');
    }

    public function decide(Request $request, string $campaign, string $approval, Campaigns $campaigns, CampaignApprovals $approvals): RedirectResponse
    {
        $this->authorizePanel(Permission::CampaignsApprove);
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'reason' => 'nullable|string|max:500']);
        try {
            $approvals->decide($approval, $this->find($campaigns, $campaign), $this->userId(), $data['decision'] === 'approve', $data['reason'] ?? null);
        } catch (ApprovalError $e) {
            return $this->failed(PanelMessages::for($e->reason, $e->getMessage()));
        }

        return $this->done($data['decision'] === 'approve' ? 'Onay verildi.' : 'Reddedildi.');
    }

    public function approvals(): View
    {
        $this->authorizePanel(Permission::CampaignsView);
        $rows = DB::table('campaign_approvals as a')->join('campaigns as c', fn ($j) => $j->on('c.id', '=', 'a.campaign_id')->on('c.tenant_id', '=', 'a.tenant_id'))
            ->orderByRaw("CASE a.status WHEN 'pending' THEN 0 ELSE 1 END")->orderByDesc('a.requested_at')->limit(100)
            ->get(['a.*', 'c.name as campaign', 'c.status as campaign_status']);

        return view('panel.approvals', ['rows' => $rows, 'people' => $this->people(), 'me' => $this->userId(), 'canApprove' => $this->can(Permission::CampaignsApprove)]);
    }

    /**
     * Display names of this tenant's members (approval history labels).
     *
     * @return Collection<string, string>
     */
    private function people(): Collection
    {
        return DB::table('tenant_users as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.tenant_id', app(TenantContext::class)->requireTenant())->pluck('u.name', 'u.id')->map(fn ($n) => (string) $n);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'segments' => DB::table('segments')->orderBy('name')->get(['id', 'name']),
            'versions' => DB::table('email_template_versions as v')->join('email_templates as t', 't.id', '=', 'v.template_id')->where('t.message_type', 'marketing')
                ->where('v.status', 'published')->orderBy('t.name')->orderByDesc('v.version')->get(['v.id', 't.name', 'v.version']),
            'senders' => DB::table('email_sender_identities as s')->join('tenant_integrations as i', 'i.id', '=', 's.integration_id')
                ->get(['s.id', 's.integration_id', 's.from_email', 's.verification_status', 's.dkim_status', 's.mail_from_status', 'i.provider', DB::raw("i.settings->>'region' AS region")]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        $data = $request->validate(['name' => 'required|string|max:160', 'type' => 'required|in:newsletter,promotional,announcement', 'segment_id' => 'nullable|uuid',
            'template_version_id' => 'nullable|uuid', 'sender_identity_id' => 'nullable|uuid', 'notes' => 'nullable|string|max:500']);
        $integration = isset($data['sender_identity_id']) ? DB::table('email_sender_identities')->where('id', $data['sender_identity_id'])->value('integration_id') : null;

        return ['name' => $data['name'], 'type' => $data['type'], 'audience_source' => 'segment', 'segment_id' => $data['segment_id'] ?? null,
            'template_version_id' => $data['template_version_id'] ?? null, 'sender_identity_id' => $data['sender_identity_id'] ?? null, 'integration_id' => $integration,
            'settings' => ['notes' => $data['notes'] ?? null]];
    }

    private function find(Campaigns $campaigns, string $id): object
    {
        try {
            return $campaigns->find($id);
        } catch (CampaignError) {
            abort(404);
        }
    }
}
