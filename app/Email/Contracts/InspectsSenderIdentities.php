<?php

namespace App\Email\Contracts;

/**
 * Optional provider capability: read the verification state of a sender
 * identity and the DNS records it needs. Read-only.
 */
interface InspectsSenderIdentities
{
    /**
     * @return array{verification_status: string, dkim_status: string, mail_from_domain: ?string, mail_from_status: string, spf_status: string, dns_records: list<array<string, string>>}
     */
    public function inspectIdentity(string $providerIdentityId, string $domain): array;
}
