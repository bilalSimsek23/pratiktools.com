<?php

namespace App\Connectors\Ses;

/**
 * Translates a GetEmailIdentity answer into provider-neutral statuses and
 * the DNS records the tenant must publish. Records are only shown, never
 * applied: PratikTools does not change DNS.
 *
 * Per the SES documentation: Easy DKIM verifies the domain with three
 * CNAMEs {token}._domainkey.{domain} → {token}.{SigningHostedZone}; a
 * custom MAIL FROM needs exactly one MX "10 feedback-smtp.{region}.amazonses.com"
 * and a TXT "v=spf1 include:amazonses.com ~all" on that subdomain; DMARC
 * is a TXT on _dmarc.{domain}, starting with p=none.
 */
final class SesIdentityRecords
{
    /**
     * @param  array<string, mixed>  $identity  GetEmailIdentity response
     * @return array{verification_status: string, dkim_status: string, mail_from_domain: ?string, mail_from_status: string, spf_status: string, dns_records: list<array<string, string>>}
     */
    public static function from(array $identity, string $domain, string $region): array
    {
        $dkim = is_array($identity['DkimAttributes'] ?? null) ? $identity['DkimAttributes'] : [];
        $mailFrom = is_array($identity['MailFromAttributes'] ?? null) ? $identity['MailFromAttributes'] : [];
        $zone = is_string($dkim['SigningHostedZone'] ?? null) && $dkim['SigningHostedZone'] !== '' ? $dkim['SigningHostedZone'] : 'dkim.amazonses.com';
        $mailFromDomain = is_string($mailFrom['MailFromDomain'] ?? null) && $mailFrom['MailFromDomain'] !== '' ? strtolower($mailFrom['MailFromDomain']) : null;

        $records = [];
        foreach ((array) ($dkim['Tokens'] ?? []) as $token) {
            if (is_string($token) && preg_match('/^[A-Za-z0-9_-]{1,63}$/', $token) === 1) {
                $records[] = ['purpose' => 'dkim', 'type' => 'CNAME', 'name' => "{$token}._domainkey.{$domain}", 'value' => "{$token}.{$zone}"];
            }
        }
        if ($mailFromDomain !== null) {
            $records[] = ['purpose' => 'mail_from', 'type' => 'MX', 'name' => $mailFromDomain, 'value' => "10 feedback-smtp.{$region}.amazonses.com"];
            $records[] = ['purpose' => 'spf', 'type' => 'TXT', 'name' => $mailFromDomain, 'value' => '"v=spf1 include:amazonses.com ~all"'];
        }
        $records[] = ['purpose' => 'dmarc', 'type' => 'TXT', 'name' => "_dmarc.{$domain}", 'value' => '"v=DMARC1; p=none; rua=mailto:dmarc-reports@'.$domain.'"', 'note' => 'recommendation: start with p=none, move to quarantine/reject after reviewing reports'];

        $mailFromStatus = $mailFromDomain === null ? 'not_configured' : self::status((string) ($mailFrom['MailFromDomainStatus'] ?? ''));

        return [
            'verification_status' => ($identity['VerifiedForSendingStatus'] ?? false) === true ? 'verified' : self::status((string) ($identity['VerificationStatus'] ?? '')),
            'dkim_status' => ($dkim['SigningEnabled'] ?? false) === true ? self::status((string) ($dkim['Status'] ?? '')) : 'disabled',
            'mail_from_domain' => $mailFromDomain,
            'mail_from_status' => $mailFromStatus,
            // SPF passes on SES's own MAIL FROM, but only aligns with the From
            // domain (DMARC via SPF) when a custom MAIL FROM is verified.
            'spf_status' => $mailFromStatus === 'verified' ? 'aligned' : 'provider_default',
            'dns_records' => $records,
        ];
    }

    private static function status(string $ses): string
    {
        return match (strtoupper($ses)) {
            'SUCCESS' => 'verified',
            'PENDING' => 'pending',
            'FAILED' => 'failed',
            'TEMPORARY_FAILURE' => 'temporary_failure',
            'NOT_STARTED' => 'not_started',
            default => 'unknown',
        };
    }
}
