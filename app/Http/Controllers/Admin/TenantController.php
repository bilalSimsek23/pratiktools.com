<?php

namespace App\Http\Controllers\Admin;

use App\Audit\Actor;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Platform\PlatformTenantNotes;
use App\Platform\PlatformTenants;
use App\Platform\TenantLifecycle;
use App\Platform\TenantMetadata;
use App\Platform\TenantSes;
use App\Platform\TenantTicimax;
use App\Tenancy\ProvisioningError;
use App\Tenancy\TenantProvisioner;
use App\Tenancy\TenantRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Super Admin › Müşteriler. Reads through the platform connection
 * (PlatformTenants); changes through the shared tenant services, which run
 * in the tenant's own context on the runtime connection. The tenant id
 * comes from the URL of an authenticated, second-factor Super Admin and is
 * checked to exist; no tenant context outlives the call.
 */
final class TenantController extends Controller
{
    public const TABS = ['genel' => 'Genel', 'kullanicilar' => 'Kullanıcılar', 'entegrasyonlar' => 'Entegrasyonlar', 'kullanim' => 'Kullanım', 'audit' => 'Audit'];

    public function index(Request $request, PlatformTenants $tenants): View
    {
        $status = in_array($request->query('durum'), ['active', 'suspended'], true) ? (string) $request->query('durum') : null;
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 120);

        return view('admin.tenants.index', ['tenants' => $tenants->list($q, $status), 'q' => $q, 'status' => $status]);
    }

    public function create(): View
    {
        return view('admin.tenants.create');
    }

    public function store(Request $request, TenantProvisioner $provisioner, PlatformTenantNotes $notes): RedirectResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:63', 'regex:'.TenantProvisioner::SLUG_PATTERN],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ()-]{7,24}$/'],
            'platform_note' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $result = $provisioner->provision(
                ['name' => $d['name'], 'slug' => $d['slug'], 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'currency' => 'TRY', 'locale' => 'tr',
                    'contact_phone' => $d['contact_phone'] ?? null],
                ['email' => $d['owner_email'], 'name' => $d['owner_name']],
                $this->actor(),
            );
        } catch (ProvisioningError $e) {
            return back()->withInput()->withErrors(['slug' => $e->getMessage()]);
        }
        // Platform-only note: separate table on the platform connection, after the tenant exists.
        if ($result->created && trim((string) ($d['platform_note'] ?? '')) !== '') {
            try {
                $notes->set($result->tenant->id, (string) $d['platform_note'], (string) $this->actor()->id);
            } catch (ProvisioningError $e) {
                session()->flash('note_error', $e->getMessage());
            }
        }
        $to = redirect()->route('admin.tenants.show', [$result->tenant->id, 'tab' => 'kullanicilar']);

        return $result->created
            ? $to->with('status', 'Müşteri oluşturuldu. Owner davet bağlantısını aşağıdan kopyalayıp güvenli bir kanaldan iletin; bağlantı yalnız bir kez gösterilir.')
                ->with('invitation', ['url' => $result->invitation?->url, 'expires_at' => $result->invitation?->expiresAt->toIso8601String(), 'email' => $result->owner->email])
            : $to->with('status', 'Bu müşteri bu owner ile zaten oluşturulmuş; hiçbir şey değiştirilmedi.');
    }

    public function show(Request $request, string $tenant, PlatformTenants $tenants, TenantTicimax $ticimax, TenantSes $ses): View
    {
        $t = $tenants->find($tenant) ?? abort(404);
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'genel';

        return view('admin.tenants.show', ['t' => $t, 'tab' => $tab, 'tabs' => self::TABS, 'members' => $tenants->members($t->id), 'integrations' => $tenants->integrations($t->id),
            'usage' => $tenants->usage($t->id), 'audit' => $tab === 'audit' ? $tenants->audit($t->id) : collect(), 'roles' => TenantRole::cases(),
            'ticimax' => $tab === 'entegrasyonlar' ? $ticimax->readiness($t->id) : null, 'ses' => $tab === 'entegrasyonlar' ? $ses->readiness($t->id) : null]);
    }

    public function status(Request $request, string $tenant, PlatformTenants $tenants, TenantLifecycle $lifecycle): RedirectResponse
    {
        $t = $tenants->find($tenant) ?? abort(404);
        $d = $request->validate(['action' => ['required', 'in:suspend,reactivate'], 'reason' => ['nullable', 'string', 'max:255'], 'confirm_slug' => ['required', 'string']]);
        if ($d['confirm_slug'] !== $t->slug) {
            return back()->withErrors(['confirm_slug' => 'Onay için müşterinin kodunu ('.$t->slug.') aynen yazın.']);
        }
        try {
            $d['action'] === 'suspend' ? $lifecycle->suspend($t->id, (string) ($d['reason'] ?? ''), $this->actor()) : $lifecycle->reactivate($t->id, $this->actor());
        } catch (ProvisioningError $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return back()->with('status', $d['action'] === 'suspend' ? 'Müşteri askıya alındı. Veriler silinmedi.' : 'Müşteri yeniden aktif.');
    }

    /**
     * Genel › Temel bilgiler: name, code, phone, region and language. The id,
     * public id, owner, status and data key are never changed here.
     */
    public function metadata(Request $request, string $tenant, PlatformTenants $tenants, TenantMetadata $metadata): RedirectResponse
    {
        $t = $tenants->find($tenant) ?? abort(404);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'slug' => ['required', 'string', 'max:63'], 'slug_confirmation' => ['nullable', 'string', 'max:63'],
            'contact_phone' => ['nullable', 'string', 'max:32'], 'country' => ['required', 'string', 'size:2'], 'timezone' => ['required', 'string', 'max:64'],
            'currency' => ['required', 'string', 'size:3'], 'locale' => ['required', 'string', 'max:16'],
        ]);
        try {
            $changed = $metadata->update($t->id, $d, $d['slug_confirmation'] ?? null, $this->actor());
        } catch (ProvisioningError $e) {
            return back()->withInput()->withErrors(['metadata' => $e->getMessage()]);
        }

        return back()->with('status', $changed === [] ? 'Değişiklik yok.' : 'Müşteri bilgileri güncellendi.');
    }

    public function note(Request $request, string $tenant, PlatformTenants $tenants, PlatformTenantNotes $notes): RedirectResponse
    {
        $t = $tenants->find($tenant) ?? abort(404);
        $note = (string) ($request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? '');
        try {
            $notes->set($t->id, $note, (string) $this->actor()->id);
        } catch (ProvisioningError $e) {
            return back()->withInput()->withErrors(['note' => $e->getMessage()]);
        }

        return back()->with('status', 'Platform notu kaydedildi.');
    }

    private function actor(): Actor
    {
        /** @var PlatformAdmin $admin */
        $admin = Auth::guard('platform')->user();

        return Actor::platformAdmin($admin->id);
    }
}
