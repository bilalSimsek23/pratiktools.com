<?php

namespace App\Http\Controllers\Admin;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsurePlatformTwoFactor;
use App\Platform\PlatformAdminActivations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super Admin activation link (/activate/{token} on the admin host): the
 * invited admin chooses their own password, is signed in and must set up
 * two-factor authentication before any Super Admin page opens. A bad
 * signature and an invalid, used, replaced or expired token all get the
 * same 410 answer (checked here, not by the "signed" middleware's 403).
 */
final class ActivationController extends Controller
{
    public function __construct(private readonly PlatformAdminActivations $activations) {}

    public function show(Request $request, string $token): Response
    {
        $admin = $request->hasValidRelativeSignature() ? $this->activations->resolve($token) : null;
        if ($admin === null) {
            return $this->invalid();
        }

        return response()->view('admin.activate', ['email' => $admin->email, 'name' => $admin->name, 'action' => $request->fullUrl()])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function activate(Request $request, string $token, AuditLogger $audit): RedirectResponse|Response
    {
        if (! $request->hasValidRelativeSignature() || $this->activations->resolve($token) === null) {
            return $this->invalid();
        }
        $password = (string) $request->validate(['password' => ['required', 'string', 'confirmed', 'max:255', Password::min(16)]])['password'];
        $admin = $this->activations->activate($token, $password);
        if ($admin === null) {
            return $this->invalid();
        }

        Auth::guard('platform')->login($admin);
        $request->session()->regenerate();
        $request->session()->forget(EnsurePlatformTwoFactor::SESSION_KEY);
        $admin->forceFill(['last_login_at' => now()])->save();
        $audit->record(AuditAction::PlatformLogin, 'platform_admin', $admin->id, ['via' => 'activation'], Actor::platformAdmin($admin->id), tenantId: false, connection: 'pgsql_platform');

        return redirect()->route('admin.two-factor.setup');
    }

    private function invalid(): Response
    {
        return response()->view('admin.activate-invalid', [], 410)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
