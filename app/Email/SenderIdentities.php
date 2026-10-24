<?php

namespace App\Email;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Email\Contracts\InspectsSenderIdentities;
use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tenant sender identities. Saving one only records what the tenant
 * chose (which domain or subdomain is theirs to decide); it never
 * creates, verifies or changes anything at the provider or in DNS.
 * refresh() reads the provider's verification state and shows the
 * records still to publish; DMARC presence is a read-only DNS lookup.
 */
final class SenderIdentities
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EmailProviderResolver $providers,
        private readonly DnsLookup $dns,
        private readonly AuditLogger $audit,
    ) {}

    public function save(TenantIntegration $integration, string $fromEmail, ?string $fromName, ?string $replyTo, ?string $providerIdentity, Actor $actor): string
    {
        $tenantId = $this->context->requireTenant();
        $from = MessageRules::address($fromEmail, 'INVALID_FROM');
        $domain = substr($from, strrpos($from, '@') + 1);
        $name = MessageRules::line($fromName, 120, 'INVALID_FROM_NAME', required: false);
        $reply = $replyTo === null || $replyTo === '' ? null : MessageRules::address($replyTo, 'INVALID_REPLY_TO');
        $identity = strtolower($providerIdentity ?? $domain);
        if ($identity !== $from && $identity !== $domain && ! str_ends_with($domain, '.'.$identity)) {
            throw new InvalidEmailMessage('IDENTITY_MISMATCH', 'The provider identity must be the from address, its domain or a parent domain.');
        }

        $existing = DB::table('email_sender_identities')->where('tenant_id', $tenantId)->where('integration_id', $integration->id)->where('from_email', $from)->value('id');
        $id = $existing ?? (string) Str::uuid7();
        DB::table('email_sender_identities')->upsert([[
            'id' => $id, 'tenant_id' => $tenantId, 'integration_id' => $integration->id, 'provider' => $integration->provider, 'domain' => $domain,
            'from_email' => $from, 'from_name' => $name, 'reply_to_email' => $reply, 'provider_identity_id' => $identity,
            'created_at' => now(), 'updated_at' => now(),
        ]], ['tenant_id', 'integration_id', 'from_email'], ['from_name', 'reply_to_email', 'provider_identity_id', 'domain', 'updated_at']);
        $this->audit->record(AuditAction::EmailSenderIdentitySaved, 'email_sender_identity', $id, ['domain' => $domain, 'provider' => $integration->provider], $actor);

        return $id;
    }

    /**
     * @return array<string, mixed> the refreshed row
     */
    public function refresh(TenantIntegration $integration, string $identityId, Actor $actor): array
    {
        $tenantId = $this->context->requireTenant();
        $row = DB::table('email_sender_identities')->where('tenant_id', $tenantId)->where('integration_id', $integration->id)->where('id', $identityId)->first()
            ?? throw new InvalidEmailMessage('UNKNOWN_SENDER', 'Unknown sender identity.');

        $provider = $this->providers->provider($integration, 'email_identity_check', $actor);
        if (! $provider instanceof InspectsSenderIdentities) {
            throw new InvalidEmailMessage('CAPABILITY_MISSING', 'This provider cannot report identity verification.');
        }
        $status = $provider->inspectIdentity($row->provider_identity_id, $row->domain);
        $dmarc = $this->dns->txt('_dmarc.'.$row->domain);
        $dmarcRecord = $dmarc === null ? null : collect($dmarc)->first(fn (string $t) => str_starts_with(strtolower(trim($t, '" ')), 'v=dmarc1'));

        $values = [
            'verification_status' => $status['verification_status'],
            'dkim_status' => $status['dkim_status'],
            'mail_from_domain' => $status['mail_from_domain'],
            'mail_from_status' => $status['mail_from_status'],
            'spf_status' => $status['spf_status'],
            'dmarc_status' => match (true) {
                $dmarc === null => 'unknown',
                $dmarcRecord === null => 'missing',
                default => 'p='.(preg_match('/\bp=([a-z]+)/i', $dmarcRecord, $m) === 1 ? strtolower($m[1]) : 'invalid'),
            },
            'dns_records' => json_encode($status['dns_records']),
            'last_checked_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('email_sender_identities')->where('tenant_id', $tenantId)->where('id', $identityId)->update($values);

        return ['id' => $identityId, 'domain' => $row->domain, 'from_email' => $row->from_email] + $values + ['dns_records' => $status['dns_records']];
    }
}
