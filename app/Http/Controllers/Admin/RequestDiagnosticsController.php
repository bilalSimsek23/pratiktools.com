<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super Admin only: how the application sees the current request behind
 * the edge proxy (scheme, client IP, host). Used once after a deploy to
 * verify the trusted proxy setup. Shows only the caller's own request.
 */
final class RequestDiagnosticsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'secure' => $request->isSecure(),
            'scheme' => $request->getScheme(),
            'host' => $request->getHost(),
            'client_ip' => $request->ip(),
            'forwarded_for_hops' => count(array_filter(explode(',', (string) $request->headers->get('X-Forwarded-For')))),
            'url' => url('/'),
            'from_trusted_proxy' => $request->isFromTrustedProxy(),
        ]);
    }
}
