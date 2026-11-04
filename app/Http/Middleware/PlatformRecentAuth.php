<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super Admin changes to a tenant (create, suspend, invite, role and
 * membership changes) need the admin's password confirmed within
 * platform.reauth_seconds, on top of the session's second factor.
 */
final class PlatformRecentAuth
{
    public const SESSION_KEY = 'platform.auth_confirmed_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (time() - (int) $request->session()->get(self::SESSION_KEY, 0) > (int) config('platform.reauth_seconds')) {
            $request->session()->put('platform.intended', url()->previous());

            return redirect()->route('admin.confirm')->withErrors(['password' => 'Bu işlem için şifrenizi tekrar doğrulayın.']);
        }

        return $next($request);
    }
}
