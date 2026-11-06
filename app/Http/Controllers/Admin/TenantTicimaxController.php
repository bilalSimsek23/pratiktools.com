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
 * back into the form or flashed to the session; nothing here starts a sync.
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

    private function actor(): Actor
    {
        /** @var PlatformAdmin $admin */
        $admin = Auth::guard('platform')->user();

        return Actor::platformAdmin($admin->id);
    }
}
