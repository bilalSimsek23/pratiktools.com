<?php

namespace App\Http\Controllers\Panel;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Middleware\PanelRecentAuth;
use App\Http\Middleware\PanelTenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(): View
    {
        return view('panel.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255'], 'password' => ['required', 'string', 'max:255']]);
        if (! Auth::guard('web')->attempt(['email' => Str::lower($data['email']), 'password' => $data['password']])) {
            $this->audit->record(AuditAction::PanelLoginFailed, actor: Actor::system(), tenantId: false);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        $request->session()->regenerate();
        $request->session()->put(PanelRecentAuth::SESSION_KEY, time());
        $this->audit->record(AuditAction::PanelLogin, 'user', (string) Auth::guard('web')->id(), [], Actor::user((string) Auth::guard('web')->id()), tenantId: false);

        $tenants = $this->memberships();

        return count($tenants) === 1 ? $this->enter($request, $tenants[0]->tenant_id) : redirect()->route('panel.tenants');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('panel.login');
    }

    public function tenants(): View
    {
        return view('panel.tenants', ['tenants' => $this->memberships()]);
    }

    public function chooseTenant(Request $request): RedirectResponse
    {
        $tenantId = (string) $request->validate(['tenant' => ['required', 'uuid']])['tenant'];
        abort_unless(collect($this->memberships())->contains('tenant_id', $tenantId), 403);

        return $this->enter($request, $tenantId);
    }

    public function confirmForm(): View
    {
        return view('panel.confirm');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $password = (string) $request->validate(['password' => ['required', 'string', 'max:255']])['password'];
        /** @var User $user */
        $user = Auth::guard('web')->user();
        if (! Hash::check($password, (string) $user->password)) {
            throw ValidationException::withMessages(['password' => __('auth.password')]);
        }
        $request->session()->put(PanelRecentAuth::SESSION_KEY, time());
        $this->audit->record(AuditAction::PanelReauthenticated, 'user', $user->id, [], Actor::user($user->id), tenantId: false);

        return redirect()->to((string) $request->session()->pull('panel.intended', route('panel.dashboard')))->with('status', 'Şifre doğrulandı; işlemi tekrar gönderin.');
    }

    /**
     * Active memberships of active tenants (own rows, via the RLS user policy).
     *
     * @return list<\stdClass>
     */
    private function memberships(): array
    {
        $userId = (string) Auth::guard('web')->id();
        $context = app(TenantContext::class);
        // RLS shows a user their own memberships only once the user is set.
        $context->setUser($userId);
        try {
            return DB::table('tenant_users as m')->join('tenants as t', 't.id', '=', 'm.tenant_id')->where('m.user_id', $userId)->where('m.status', 'active')
                ->where('t.status', 'active')->orderBy('t.name')->get(['m.tenant_id', 't.name', 'm.role'])->all();
        } finally {
            $context->clear();
        }
    }

    private function enter(Request $request, string $tenantId): RedirectResponse
    {
        $request->session()->put(PanelTenant::SESSION_TENANT, $tenantId);
        app(TenantContext::class)->clear();

        return redirect()->route('panel.dashboard');
    }
}
