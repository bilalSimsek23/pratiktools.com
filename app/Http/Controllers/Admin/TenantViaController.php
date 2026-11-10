<?php

namespace App\Http\Controllers\Admin;

use App\Audit\Actor;
use App\Consent\Via\ViaFormStatus;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Platform\PlatformTenants;
use App\Platform\TenantViaFrame;
use App\Tenancy\ProvisioningError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Super Admin › Müşteriler › {tenant} › Entegrasyonlar › İYS / ViA: the
 * tenant's ViA Frame configuration, readiness and embed code. Changes need
 * a recent password confirmation (route middleware) and are audited.
 * Nothing here contacts ViA or İYS, starts an OTP or writes consent.
 */
final class TenantViaController extends Controller
{
    public function show(string $tenant, PlatformTenants $tenants, TenantViaFrame $via): View
    {
        $t = $tenants->find($tenant) ?? abort(404);

        return view('admin.tenants.via', ['t' => $t, 'statuses' => ViaFormStatus::cases()] + $via->show($t->id));
    }

    public function update(Request $request, string $tenant, PlatformTenants $tenants, TenantViaFrame $via): RedirectResponse
    {
        $t = $tenants->find($tenant) ?? abort(404);
        $d = $request->validate([
            'iys_number' => ['nullable', 'string', 'max:12'], 'brand' => ['nullable', 'string', 'max:128'], 'package_status' => ['nullable', 'in:active,inactive'],
            'form_id' => ['nullable', 'string', 'max:64'], 'widget_uuid' => ['nullable', 'string', 'max:64'], 'frame_origins' => ['nullable', 'string', 'max:2000'],
        ]);
        $values = array_filter(['iys_number' => $d['iys_number'] ?? null, 'brand' => $d['brand'] ?? null, 'package_status' => $d['package_status'] ?? null,
            'form_id' => $d['form_id'] ?? null, 'widget_uuid' => $d['widget_uuid'] ?? null], fn ($v) => is_string($v) && trim($v) !== '');
        // One origin per line (or comma separated); an empty field clears them.
        $values['frame_origins'] = preg_split('/[\s,]+/', (string) ($d['frame_origins'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        try {
            $via->configure($t->id, $values, $this->actor());
        } catch (ProvisioningError $e) {
            return back()->withInput()->withErrors(['via' => $e->getMessage()]);
        }

        return back()->with('status', 'ViA yapılandırması kaydedildi.');
    }

    public function status(Request $request, string $tenant, PlatformTenants $tenants, TenantViaFrame $via): RedirectResponse
    {
        $t = $tenants->find($tenant) ?? abort(404);
        $d = $request->validate(['status' => ['required', 'in:'.implode(',', array_column(ViaFormStatus::cases(), 'value'))], 'note' => ['required', 'string', 'max:255'],
            'portal_confirmed' => ['nullable', 'boolean']]);
        try {
            $via->setFormStatus($t->id, ViaFormStatus::from($d['status']), $d['note'], (bool) ($d['portal_confirmed'] ?? false), $this->actor());
        } catch (ProvisioningError $e) {
            return back()->withErrors(['via_status' => $e->getMessage()]);
        }

        return back()->with('status', 'Form durumu güncellendi.');
    }

    public function domainActivation(Request $request, string $tenant, PlatformTenants $tenants, TenantViaFrame $via): RedirectResponse
    {
        $t = $tenants->find($tenant) ?? abort(404);
        $d = $request->validate(['origin' => ['required', 'string', 'max:255'], 'note' => ['required', 'string', 'max:255'], 'iys_confirmed' => ['nullable', 'boolean']]);
        try {
            $via->confirmDomainActivation($t->id, $d['origin'], $d['note'], (bool) ($d['iys_confirmed'] ?? false), $this->actor());
        } catch (ProvisioningError $e) {
            return back()->withErrors(['via_activation' => $e->getMessage()]);
        }

        return back()->with('status', 'İYS domain aktivasyonu teyidi kaydedildi.');
    }

    private function actor(): Actor
    {
        /** @var PlatformAdmin $admin */
        $admin = Auth::guard('platform')->user();

        return Actor::platformAdmin($admin->id);
    }
}
