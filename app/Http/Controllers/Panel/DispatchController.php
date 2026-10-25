<?php

namespace App\Http\Controllers\Panel;

use App\Campaigns\CampaignError;
use App\Campaigns\Campaigns;
use App\Dispatch\CampaignDispatches;
use App\Dispatch\DispatchBlocked;
use App\Dispatch\DispatchGuard;
use App\Dispatch\DispatchObservability;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\PanelHelpers;
use App\Panel\PanelMessages;
use App\Tenancy\Permission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Test-only dispatch: recipients are chosen from the canonical allowlist
 * (ids, never typed addresses); live is not offered and refused by the
 * backend guard.
 */
final class DispatchController extends Controller
{
    use PanelHelpers;

    public function index(): View
    {
        $this->authorizePanel(Permission::CampaignsView);
        $rows = DB::table('campaign_dispatches as d')->join('campaigns as c', fn ($j) => $j->on('c.id', '=', 'd.campaign_id')->on('c.tenant_id', '=', 'd.tenant_id'))
            ->orderByDesc('d.requested_at')->limit(100)->get(['d.*', 'c.name as campaign']);

        return view('panel.dispatches.index', ['rows' => $rows]);
    }

    public function create(string $campaign, Campaigns $campaigns): View
    {
        $this->authorizePanel(Permission::CampaignsManage);
        $c = $this->campaign($campaigns, $campaign);

        return view('panel.dispatches.create', [
            'c' => $c,
            'emailRecipients' => DB::table('test_recipients')->where('channel', 'email')->where('active', true)->orderBy('label')->get(['id', 'label', 'provider_recipient_id']),
            'pushRecipients' => DB::table('test_recipients')->where('channel', 'push')->where('active', true)->orderBy('label')->get(['id', 'label', 'provider_recipient_id']),
            'liveAvailable' => DispatchGuard::LIVE_AVAILABLE,
        ]);
    }

    public function store(Request $request, string $campaign, CampaignDispatches $dispatches): RedirectResponse
    {
        $this->authorizePanel(Permission::CampaignsManage);
        $data = $request->validate(['channel' => 'required|in:email,push', 'recipients' => 'required|array|min:1', 'recipients.*' => 'uuid',
            'title' => 'nullable|string|max:120', 'body' => 'nullable|string|max:1000', 'new_generation' => 'nullable|boolean']);
        try {
            $id = $dispatches->request($campaign, $data['channel'], 'test_only', $data['recipients'], $this->userId(),
                ['title' => (string) ($data['title'] ?? ''), 'body' => (string) ($data['body'] ?? '')], (bool) ($data['new_generation'] ?? false));
        } catch (DispatchBlocked $e) {
            return $this->failed(PanelMessages::for($e->reason, $e->getMessage()));
        } catch (InvalidArgumentException $e) {
            return $this->failed($e->getMessage());
        }

        return redirect()->route('panel.dispatches.show', $id)->with('status', 'Test gönderim talebi oluşturuldu.');
    }

    public function show(string $dispatch, CampaignDispatches $dispatches, DispatchObservability $observability): View
    {
        $this->authorizePanel(Permission::CampaignsView);
        try {
            $d = $dispatches->refresh($dispatch);
        } catch (DispatchBlocked) {
            abort(404);
        }
        $ids = (array) json_decode($d->recipients, true);

        return view('panel.dispatches.show', [
            'd' => $d,
            'campaign' => DB::table('campaigns')->where('id', $d->campaign_id)->first(['id', 'name', 'status']),
            'plan' => (array) json_decode($d->plan, true),
            'recipients' => DB::table('test_recipients')->whereIn('id', $ids ?: ['00000000-0000-0000-0000-000000000000'])->get(['label', 'channel']),
            'observed' => $observability->for($d),
            'canQueue' => $this->can(Permission::CampaignsTestDispatch) && in_array($d->status, ['approved', 'failed'], true),
            'canCancel' => $this->can(Permission::CampaignsManage) && in_array($d->status, ['draft', 'awaiting_approval', 'approved', 'queued', 'blocked', 'failed'], true),
        ]);
    }

    public function queue(string $dispatch, CampaignDispatches $dispatches): RedirectResponse
    {
        $this->authorizePanel(Permission::CampaignsTestDispatch);
        try {
            $dispatches->queue($dispatch, $this->userId());
        } catch (DispatchBlocked $e) {
            return $this->failed(PanelMessages::for($e->reason, $e->getMessage()));
        }

        return $this->done('Test gönderimi kuyruğa alındı.');
    }

    public function cancel(string $dispatch, CampaignDispatches $dispatches): RedirectResponse
    {
        $this->authorizePanel(Permission::CampaignsManage);
        try {
            $dispatches->cancel($dispatch, $this->userId());
        } catch (DispatchBlocked $e) {
            return $this->failed(PanelMessages::for($e->reason, $e->getMessage()));
        }

        return $this->done('Gönderim talebi iptal edildi.');
    }

    private function campaign(Campaigns $campaigns, string $id): object
    {
        try {
            return $campaigns->find($id);
        } catch (CampaignError) {
            abort(404);
        }
    }
}
