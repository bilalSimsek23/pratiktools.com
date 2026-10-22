<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant API and webhook routes are never served on the admin host.
 */
final class RejectAdminHost
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->getHost() === config('platform.admin_domain'), 404);

        return $next($request);
    }
}
