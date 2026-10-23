<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Trusts exactly the proxy that connects to the application, and only for
 * client IP, scheme and port.
 *
 * Laravel's default on Laravel Cloud is "*", which trusts every address
 * (0.0.0.0/0). Then every X-Forwarded-For entry counts as a proxy and the
 * left-most value, which the client controls, becomes the client IP; and
 * X-Forwarded-Host is honoured. Here:
 * - only the immediate peer (REMOTE_ADDR) is trusted, so the client IP is
 *   the right-most X-Forwarded-For entry, the one the edge appended;
 * - X-Forwarded-Host is never trusted (URLs come from APP_URL / Host);
 * - TRUSTED_PROXIES may list extra proxy CIDRs if the edge has more than
 *   one hop; "*" is never expanded to "trust everyone".
 */
final class TrustCloudProxies extends TrustProxies
{
    /** @var int */
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO;

    protected function setTrustedProxyIpAddresses(Request $request)
    {
        $configured = config('platform.trusted_proxies');
        $value = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : (laravel_cloud() || app()->isProduction() ? 'REMOTE_ADDR' : 'none');

        if ($value === 'none') {
            return;
        }

        $proxies = array_values(array_filter(array_map(
            fn (string $p) => in_array($p, ['*', '**'], true) ? 'REMOTE_ADDR' : $p,
            array_map(trim(...), explode(',', $value)),
        )));

        $this->setTrustedProxyIpAddressesToSpecificIps($request, array_values(array_unique($proxies)));
    }
}
