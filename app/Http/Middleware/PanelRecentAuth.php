<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sensitive panel actions (approvals, dispatch, publishing, form
 * activation, sending mode) need a password confirmation within
 * dispatch.reauth_seconds.
 */
final class PanelRecentAuth
{
    public const SESSION_KEY = 'panel.auth_confirmed_at';

    public function handle(Request $request, Closure $next): Response
    {
        $at = (int) $request->session()->get(self::SESSION_KEY, 0);
        if (time() - $at > (int) config('dispatch.reauth_seconds')) {
            $request->session()->put('panel.intended', url()->previous());

            return redirect()->route('panel.confirm')->withErrors(['password' => 'Bu işlem için şifrenizi tekrar doğrulayın.']);
        }

        return $next($request);
    }
}
