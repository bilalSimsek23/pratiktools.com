<?php

namespace App\Http\Controllers\Panel;

use App\Commerce\Sync\CommerceRelink;
use App\Consent\Via\ViaConfigurations;
use App\Consent\Via\ViaFormStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\PanelHelpers;
use App\Notifications\TenantNotifications;
use App\Operations\ExternalBlockers;
use App\Operations\IntegrationStatus;
use App\Operations\ReadinessCatalog;
use App\Operations\SendingModes;
use App\Tenancy\Permission;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;

final class OperationsController extends Controller
{
    use PanelHelpers;

    public function integrations(IntegrationStatus $status, ExternalBlockers $blockers): View
    {
        $this->authorizePanel(Permission::IntegrationsView);
        $s = $status->all();

        return view('panel.integrations', ['s' => $s, 'blockers' => collect($blockers->all($s))->keyBy('blocker'), 'canManageVia' => $this->can(Permission::IntegrationsManage),
            'cart' => rescue(fn () => app(CommerceRelink::class)->report(), null, false)]);
    }

    /**
     * Records the ViA form status seen in the İYS portal (audited). Approval
     * needs the explicit portal confirmation and never enables consent
     * acquisition by itself.
     */
    public function viaFormStatus(Request $request, ViaConfigurations $via): RedirectResponse
    {
        $this->authorizePanel(Permission::IntegrationsManage);
        $d = $request->validate(['status' => 'required|in:pending_approval,approved,rejected,disabled', 'note' => 'required|string|max:255', 'portal_confirmed' => 'nullable|boolean']);
        try {
            $via->setFormStatus(ViaFormStatus::from($d['status']), $this->actor(), $d['note'], (bool) ($d['portal_confirmed'] ?? false));
        } catch (InvalidArgumentException $e) {
            return $this->failed($e->getMessage());
        }

        return $this->done('ViA form durumu güncellendi.');
    }

    public function readiness(IntegrationStatus $status, ExternalBlockers $blockers): View
    {
        $this->authorizePanel(Permission::CampaignsView);

        return view('panel.readiness', ['blockers' => $blockers->all($status->all()),
            'campaigns' => DB::table('campaigns')->whereIn('status', ['draft', 'ready', 'scheduled', 'paused'])->orderByDesc('updated_at')->get(['id', 'name', 'status'])]);
    }

    public function suppressions(): View
    {
        $this->authorizePanel(Permission::SuppressionsView);

        return view('panel.suppressions', [
            'bySource' => DB::table('suppressions')->whereNull('lifted_at')->selectRaw('source, coalesce(channel, \'ALL\') AS channel, count(*) AS n')->groupBy('source', 'channel')->orderByDesc('n')->get(),
            'lifted' => DB::table('suppressions')->whereNotNull('lifted_at')->count(),
            // Unsubscribe RET: local block first, then İYS (API or ViA Ret).
            'ret' => DB::table('consent_write_intents')->where('status', 'RET')->where('source', 'UNSUBSCRIBE')
                ->selectRaw("CASE WHEN state = 'confirmed' THEN 'confirmed' WHEN state = 'failed' THEN 'failed' ELSE 'pending' END AS s, count(*) AS n")->groupBy('s')->pluck('n', 's')->map(fn ($n) => (int) $n)->all(),
            'recent' => DB::table('suppressions as s')->join('consent_recipients as r', 'r.id', '=', 's.recipient_id')->whereNull('s.lifted_at')->orderByDesc('s.created_at')->limit(25)
                ->get(['s.source', 's.channel', 's.created_at', 'r.address',
                    DB::raw("(SELECT i.state FROM consent_write_intents i WHERE i.tenant_id = s.tenant_id AND i.recipient_id = s.recipient_id AND i.status = 'RET' ORDER BY i.created_at DESC LIMIT 1) AS ret_state")])->map(function ($r) {
                        $r->address = preg_replace('/^(.).*@(.).*(\.[^.]+)$/', '$1***@$2***$3', (string) $r->address);

                        return $r;
                    }),
        ]);
    }

    public function settings(IntegrationStatus $status): View
    {
        $this->authorizePanel(Permission::TenantView);
        $s = $status->all();

        $tenantId = app(TenantContext::class)->requireTenant();

        return view('panel.settings', ['tenantMode' => DB::table('tenant_settings')->where('tenant_id', $tenantId)->value('sending_mode'), 'emailMode' => $s['amazon_ses']['sending_mode'],
            'pushMode' => $s['onesignal']['sending_mode'], 'platformEmail' => (string) config('email.sending_mode'), 'platformPush' => (string) config('push.sending_mode'),
            'canChange' => $this->can(Permission::SendingModeUpdate), 'reason' => SendingModes::LIVE_DISABLED_REASON,
            'members' => DB::table('tenant_users as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.tenant_id', $tenantId)->orderBy('u.name')->get(['u.name', 'm.role', 'm.status'])]);
    }

    public function sendingMode(Request $request, SendingModes $modes): RedirectResponse
    {
        $this->authorizePanel(Permission::SendingModeUpdate);
        try {
            $modes->set((string) $request->input('mode'), $this->actor());
        } catch (InvalidArgumentException $e) {
            return $this->failed($e->getMessage());
        }

        return $this->done('Gönderim modu güncellendi.');
    }

    public function readNotification(string $id, TenantNotifications $notifications): RedirectResponse
    {
        $notifications->markRead($id);

        return back();
    }

    /**
     * @return array{explanation: string, action: string}
     */
    public static function explain(?string $code): array
    {
        return ReadinessCatalog::explain($code);
    }
}
