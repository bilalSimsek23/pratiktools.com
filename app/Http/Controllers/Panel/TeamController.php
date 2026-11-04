<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\PanelHelpers;
use App\Tenancy\IssuedInvitation;
use App\Tenancy\Permission;
use App\Tenancy\ProvisioningError;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantMembers;
use App\Tenancy\TenantRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Ayarlar › Ekip: the tenant's own owner/admin invite teammates and manage
 * roles. The tenant is always the signed-in member's (TenantContext), never
 * a request value; the role rules live in TenantMembers.
 */
final class TeamController extends Controller
{
    use PanelHelpers;

    public function __construct(private readonly TenantContext $context, private readonly TenantMembers $members) {}

    public function index(): View
    {
        $this->authorizePanel(Permission::MembersView);
        $me = $this->context->membership();
        $tenantId = $this->context->requireTenant();

        return view('panel.team', [
            'members' => DB::table('tenant_users as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.tenant_id', $tenantId)
                ->orderByRaw("CASE m.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")->orderBy('u.name')
                ->get(['m.id', 'm.role', 'm.status', 'u.name', 'u.email', 'm.user_id']),
            'pending' => DB::table('user_invitations')->where('tenant_id', $tenantId)->whereNull('accepted_at')->whereNull('revoked_at')
                ->selectRaw('membership_id, max(expires_at) AS expires_at')->groupBy('membership_id')->pluck('expires_at', 'membership_id'),
            'assignable' => $this->can(Permission::MembersManage) ? TenantMembers::assignableBy($me->role) : [],
            'meId' => $me->id,
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $this->authorizePanel(Permission::MembersManage);
        $d = $request->validate(['email' => ['required', 'email', 'max:255'], 'name' => ['nullable', 'string', 'max:120'], 'role' => ['required', Rule::enum(TenantRole::class)]]);

        return $this->attempt(fn () => $this->members->inviteAsMember($this->context->membership(), $d['email'], (string) ($d['name'] ?? ''), TenantRole::from($d['role'])), 'Davet oluşturuldu.');
    }

    public function role(Request $request, string $member): RedirectResponse
    {
        $this->authorizePanel(Permission::MembersManage);
        $this->ownOr404('tenant_users', $member);
        $role = TenantRole::from((string) $request->validate(['role' => ['required', Rule::enum(TenantRole::class)]])['role']);

        return $this->attempt(fn () => $this->members->changeRoleAsMember($this->context->membership(), $member, $role), 'Rol güncellendi.');
    }

    public function status(Request $request, string $member): RedirectResponse
    {
        $this->authorizePanel(Permission::MembersManage);
        $this->ownOr404('tenant_users', $member);
        $status = (string) $request->validate(['status' => ['required', 'in:disabled,active']])['status'];

        return $this->attempt(fn () => $this->members->setStatusAsMember($this->context->membership(), $member, $status), $status === 'disabled' ? 'Üyelik devre dışı bırakıldı.' : 'Üyelik etkinleştirildi.');
    }

    public function reissue(string $member): RedirectResponse
    {
        $this->authorizePanel(Permission::MembersManage);
        $this->ownOr404('tenant_users', $member);

        return $this->attempt(fn () => $this->members->reissueAsMember($this->context->membership(), $member), 'Yeni davet bağlantısı oluşturuldu; önceki bağlantı artık çalışmaz.');
    }

    private function attempt(\Closure $fn, string $done): RedirectResponse
    {
        try {
            $result = $fn();
        } catch (ProvisioningError $e) {
            return $this->failed($e->getMessage());
        }

        return $result instanceof IssuedInvitation
            ? back()->with('status', $done)->with('invitation', ['url' => $result->url, 'expires_at' => $result->expiresAt->toIso8601String()])
            : $this->done($done);
    }
}
