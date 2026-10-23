<?php

namespace App\Security;

use InvalidArgumentException;

/**
 * Tenant-supplied URLs are fetched by our servers, so they must not point
 * at internal infrastructure (SSRF). Only https with a public DNS name is
 * accepted; IP literals, localhost and hosts resolving to private,
 * loopback, link-local or reserved ranges are rejected.
 */
class OutboundUrlGuard
{
    public function assertSafe(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (($parts['scheme'] ?? null) !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('URL must be https without credentials.');
        }

        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || $host === 'localhost' || ! str_contains($host, '.')) {
            throw new InvalidArgumentException('URL must use a public host name.');
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new InvalidArgumentException('URL must use the default https port.');
        }

        foreach ($this->resolve($host) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new InvalidArgumentException('URL resolves to a non-public address.');
            }
        }
    }

    /**
     * @return list<string>
     */
    protected function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = array_values(array_filter(array_map(fn (array $r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));

        if ($ips === []) {
            throw new InvalidArgumentException('URL host does not resolve.');
        }

        return $ips;
    }
}
