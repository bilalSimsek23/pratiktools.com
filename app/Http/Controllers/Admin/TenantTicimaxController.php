<?php

namespace App\Http\Controllers\Admin;

use App\Audit\Actor;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Platform\PlatformTenants;
use App\Platform\TenantTicimax;
use App\Tenancy\ProvisioningError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Super Admin › Müşteriler › {tenant} › Entegrasyonlar › Ticimax: service
 * URLs, the member code (UyeKodu, write-only) and the existing read-only
 * connection test. Changes and tests need a recent password confirmation
 * (route middleware) and are audited. The member code is never shown, put
 * back into the form or flashed to the session.
 *
 * Veri senkronizasyonu: "Kurulumu Başlat" and the per-step buttons only
 * queue the existing jobs and return; the work continues on the queue
 * (Horizon) when the browser is closed.
 */
final class TenantTicimaxController extends Controller
{
    public function show(string $tenant, PlatformTenants $tenants, TenantTicimax $ticimax): View
    {
        $t = $tenants->find($tenant) ?? abort(404);

        return view('admin.tenants.ticimax', ['t' => $t] + $ticimax->show($t->id));
    }

    public function update(Request $request, string $tenant, PlatformTenants $tenants, TenantTicimax $ticimax): RedirectResponse
    {
        $t = $tenants->find($tenant) ?? abort(404);
        $d = $request->validate([
            'service_url' => ['required', 'string', 'url:https', 'max:512'], 'storefront_url' => ['required', 'string', 'url:https', 'max:512'],
            'secret' => ['nullable', 'string', 'min:8', 'max:512'],
        ]);
        $secret = $d['secret'] ?? null;
        unset($d['secret']);
        try {
            $ticimax->configure($t->id, $d['service_url'], $d['storefront_url'], $secret, $this->actor());
        } catch (ProvisioningError $e) {
            return back()->withInput($request->except('secret'))->withErrors(['ticimax' => $e->getMessage()]);
        } finally {
            unset($secret);
        }

        return back()->with('status', $request->filled('secret') ? 'Ticimax ayarları ve yetki kodu kaydedildi. Bağlantıyı test edin.' : 'Ticimax ayarları kaydedildi.');
    }

    public function test(string $tenant, PlatformTenants $tenants, TenantTicimax $ticimax): RedirectResponse
    {
        $t = $tenants->find($tenant) ?? abort(404);
        try {
            $check = $ticimax->test($t->id, $this->actor());
        } catch (ProvisioningError $e) {
            return back()->withErrors(['ticimax_test' => $e->getMessage()]);
        }

        return $check->ok ? back()->with('status', 'Bağlantı testi başarılı.') : back()->withErrors(['ticimax_test' => 'Bağlantı testi başarısız. Ayrıntı aşağıda.']);
    }

    public function onboarding(string $tenant, PlatformTenants $tenants, TenantTicimax $ticimax): RedirectResponse
    {
        $t = $tenants->find($tenant) ?? abort(404);
        try {
            $ticimax->startOnboarding($t->id, $this->actor());
        } catch (ProvisioningError $e) {
            return back()->withErrors(['ticimax_sync' => $e->getMessage()]);
        }

        return back()->with('status', 'Kurulum kuyruğa alındı. Adımlar sırayla arka planda çalışır; bu sayfadan izleyebilirsiniz.');
    }

    public function sync(string $tenant, string $step, PlatformTenants $tenants, TenantTicimax $ticimax): RedirectResponse
    {
        $t = $tenants->find($tenant) ?? abort(404);
        try {
            $ticimax->startStep($t->id, $step, $this->actor());
        } catch (ProvisioningError $e) {
            return back()->withErrors(['ticimax_sync' => $e->getMessage()]);
        }

        return back()->with('status', 'Senkronizasyon kuyruğa alındı; arka planda çalışır.');
    }

    private function actor(): Actor
    {
        /** @var PlatformAdmin $admin */
        $admin = Auth::guard('platform')->user();

        return Actor::platformAdmin($admin->id);
    }
}
