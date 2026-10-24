<?php

namespace App\Email;

/**
 * Read-only DNS TXT lookups (DMARC presence). Replaced in tests.
 */
class DnsLookup
{
    /**
     * @return list<string>|null null when the lookup failed
     */
    public function txt(string $name): ?array
    {
        $records = @dns_get_record($name, DNS_TXT);
        if ($records === false) {
            return null;
        }

        return array_map(fn (array $r) => (string) ($r['txt'] ?? implode('', (array) ($r['entries'] ?? []))), $records);
    }
}
