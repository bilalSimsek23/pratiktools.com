<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers and a Content-Security-Policy. HTML responses
 * get a per-request nonce for inline styles/scripts; everything else gets
 * a deny-all policy.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(16));
        Vite::useCspNonce($nonce);
        View::share('cspNonce', $nonce);

        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        // Public website pages choose their own (same-origin form posts need
        // an Origin header); everything else sends no referrer.
        if (! $headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'no-referrer');
        }
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        // The panel, API, admin host and technical endpoints are never indexed.
        if ($request->is('app', 'app/*', 'api/*', 'u/*', 'webhooks/*', 'public/*', 'health*') || $request->getHost() === config('platform.admin_domain')) {
            $headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        // Sanitized e-mail previews: framed only by our own pages, no scripts,
        // no forms, no network except https images (sandboxed iframe).
        if ($headers->has('X-Panel-Preview-Frame')) {
            $headers->remove('X-Panel-Preview-Frame');
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
            $headers->set('Content-Security-Policy', "default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; font-src https:; frame-ancestors 'self'; base-uri 'none'; form-action 'none'; sandbox");

            return $response;
        }

        $isHtml = str_contains((string) $headers->get('Content-Type'), 'text/html');
        // Fixed analytics hosts a public page may add (App\Site\SiteAnalytics),
        // never free-form sources.
        $extra = (array) $request->attributes->get('csp_extra', []);
        $hosts = fn (string $k) => implode('', array_map(fn ($h) => ' '.$h, array_filter((array) ($extra[$k] ?? []), fn ($h) => preg_match('#^https://[a-z0-9.*-]+$#', (string) $h) === 1)));
        $headers->set('Content-Security-Policy', $isHtml
            ? "default-src 'self'; script-src 'self' 'nonce-{$nonce}'{$hosts('script')}; style-src 'self' 'nonce-{$nonce}'; img-src 'self' data:{$hosts('img')}; connect-src 'self'{$hosts('connect')}; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'"
            : "default-src 'none'; frame-ancestors 'none'");

        if ($request->isSecure() && app()->environment(['production', 'staging'])) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
