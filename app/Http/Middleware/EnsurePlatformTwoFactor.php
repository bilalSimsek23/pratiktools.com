<?php

namespace App\Http\Middleware;

use App\Models\PlatformAdmin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every Super Admin page requires a completed second factor in this
 * session. Admins without 2FA are sent to setup first.
 */
final class EnsurePlatformTwoFactor
{
    public const SESSION_KEY = 'platform.two_factor_passed';

    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('platform')->user();

        if (! $admin instanceof PlatformAdmin) {
            return redirect()->route('admin.login');
        }

        if (! $admin->hasTwoFactorEnabled()) {
            return redirect()->route('admin.two-factor.setup');
        }

        if ($request->session()->get(self::SESSION_KEY) !== $admin->id) {
            return redirect()->route('admin.two-factor.challenge');
        }

        return $next($request);
    }
}
