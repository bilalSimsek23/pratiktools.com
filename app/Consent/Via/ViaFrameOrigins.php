<?php

namespace App\Consent\Via;

use InvalidArgumentException;

/**
 * Origins where a tenant embeds the official ViA Frame: https only, a host
 * name (no IP, no wildcard), an optional non-default port, no path, query,
 * fragment or credentials. Normalized to lowercase "https://host[:port]",
 * duplicates removed. A trailing "/" is accepted and dropped.
 */
final class ViaFrameOrigins
{
    public const MAX = 10;

    /**
     * @param  iterable<mixed>  $origins
     * @return list<string>
     */
    public static function normalize(iterable $origins): array
    {
        $clean = [];
        foreach ($origins as $origin) {
            $o = strtolower(trim((string) $origin));
            if ($o === '') {
                continue;
            }
            if (preg_match('#^https://([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+)(?::(\d{1,5}))?/?$#', $o, $m) !== 1
                || preg_match('/^[0-9.]+$/', $m[1]) === 1 || ! preg_match('/[a-z]/', (string) substr((string) strrchr($m[1], '.'), 1))) {
                throw new InvalidArgumentException('Frame origin must be https://host[:port] with no path, query, fragment or wildcard: '.mb_substr($o, 0, 100));
            }
            $port = isset($m[2]) ? (int) $m[2] : 443;
            if ($port < 1 || $port > 65535) {
                throw new InvalidArgumentException('Frame origin has an invalid port: '.mb_substr($o, 0, 100));
            }
            $clean[] = 'https://'.$m[1].($port === 443 ? '' : ':'.$port);
        }
        $clean = array_values(array_unique($clean));
        if (count($clean) > self::MAX) {
            throw new InvalidArgumentException('At most '.self::MAX.' frame origins.');
        }

        return $clean;
    }
}
