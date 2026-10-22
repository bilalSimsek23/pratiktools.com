<?php

namespace App\Http\Controllers\Admin;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsurePlatformTwoFactor;
use App\Models\PlatformAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(): View
    {
        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $ok = Auth::guard('platform')->attempt([
            'email' => Str::lower($data['email']),
            'password' => $data['password'],
            'status' => 'active',
        ]);

        if (! $ok) {
            $this->audit->record(AuditAction::PlatformLoginFailed, actor: Actor::system(), tenantId: false, connection: 'pgsql_platform');

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();
        $request->session()->forget(EnsurePlatformTwoFactor::SESSION_KEY);

        /** @var PlatformAdmin $admin */
        $admin = Auth::guard('platform')->user();
        $admin->forceFill(['last_login_at' => now()])->save();
        $this->audit->record(AuditAction::PlatformLogin, 'platform_admin', $admin->id, [], Actor::platformAdmin($admin->id), tenantId: false, connection: 'pgsql_platform');

        return $admin->hasTwoFactorEnabled()
            ? redirect()->route('admin.two-factor.challenge')
            : redirect()->route('admin.two-factor.setup');
    }

    public function logout(Request $request): RedirectResponse
    {
        $admin = Auth::guard('platform')->user();

        if ($admin instanceof PlatformAdmin) {
            $this->audit->record(AuditAction::PlatformLogout, 'platform_admin', $admin->id, [], Actor::platformAdmin($admin->id), tenantId: false, connection: 'pgsql_platform');
        }

        Auth::guard('platform')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
