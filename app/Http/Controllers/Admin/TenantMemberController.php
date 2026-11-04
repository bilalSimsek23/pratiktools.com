<?php

namespace App\Http\Controllers\Admin;

use App\Audit\Actor;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Platform\PlatformTenants;
use App\Tenancy\IssuedInvitation;
use App\Tenancy\ProvisioningError;
use App\Tenancy\TenantMembers;
use App\Tenancy\TenantRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Super Admin › Müşteri › Kullanıcılar: invite, role, disable/enable,
 * new invitation link. Roles are the existing tenant roles. The platform
 * admin never sets anyone's password and never becomes a member.
 */
final class TenantMemberController extends Controller
{
    public function __construct(private readonly PlatformTenants $tenants, private readonly TenantMembers $members) {}

    public function store(Request $request, string $tenant): RedirectResponse
    {
        $t = $this->tenant($tenant);
        $d = $request->validate(['email' => ['required', 'email', 'max:255'], 'name' => ['nullable', 'string', 'max:120'], 'role' => ['required', Rule::enum(TenantRole::class)]]);

        return $this->attempt(fn () => $this->members->invite($t->id, $d['email'], (string) ($d['name'] ?? ''), TenantRole::from($d['role']), $this->actor()), 'Davet oluşturuldu.');
    }

    public function role(Request $request, string $tenant, string $member): RedirectResponse
    {
        $t = $this->tenant($tenant);
        $role = TenantRole::from((string) $request->validate(['role' => ['required', Rule::enum(TenantRole::class)]])['role']);

        return $this->attempt(fn () => $this->members->changeRole($t->id, $member, $role, $this->actor()), 'Rol güncellendi.');
    }

    public function status(Request $request, string $tenant, string $member): RedirectResponse
    {
        $t = $this->tenant($tenant);
        $status = (string) $request->validate(['status' => ['required', 'in:disabled,active']])['status'];

        return $this->attempt(fn () => $this->members->setStatus($t->id, $member, $status, $this->actor()), $status === 'disabled' ? 'Üyelik devre dışı bırakıldı.' : 'Üyelik etkinleştirildi.');
    }

    public function reissue(string $tenant, string $member): RedirectResponse
    {
        $t = $this->tenant($tenant);

        return $this->attempt(fn () => $this->members->reissue($t->id, $member, $this->actor()), 'Yeni davet bağlantısı oluşturuldu; önceki bağlantı artık çalışmaz.');
    }

    private function attempt(\Closure $fn, string $done): RedirectResponse
    {
        try {
            $result = $fn();
        } catch (ProvisioningError $e) {
            return back()->withInput()->withErrors(['member' => $e->getMessage()]);
        }
        $back = back()->with('status', $done);

        return $result instanceof IssuedInvitation
            ? $back->with('invitation', ['url' => $result->url, 'expires_at' => $result->expiresAt->toIso8601String()])
            : $back;
    }

    private function tenant(string $id): object
    {
        return $this->tenants->find($id) ?? abort(404);
    }

    private function actor(): Actor
    {
        /** @var PlatformAdmin $admin */
        $admin = Auth::guard('platform')->user();

        return Actor::platformAdmin($admin->id);
    }
}
