<?php

namespace App\Connectors\Ses;

use App\Integrations\Errors\ProviderError;

/**
 * The SES API v2 operations PratikTools uses. Everything except
 * sendEmail is read-only; nothing here creates or changes identities,
 * configuration sets, DNS or suppression lists.
 */
final class SesClient
{
    public function __construct(private readonly SesTransport $transport) {}

    public function transport(): SesTransport
    {
        return $this->transport;
    }

    /**
     * GET /v2/email/account: sandbox, quota, rate, enforcement status.
     *
     * @return array<string, mixed>
     */
    public function getAccount(): array
    {
        return $this->transport->request('get_account', 'GET', '/v2/email/account');
    }

    /**
     * GET /v2/email/identities/{identity}.
     *
     * @return array<string, mixed>
     */
    public function getEmailIdentity(string $identity): array
    {
        return $this->transport->request('get_email_identity', 'GET', '/v2/email/identities/'.rawurlencode($identity));
    }

    /**
     * GET /v2/email/identities (one page).
     *
     * @return array<string, mixed>
     */
    public function listEmailIdentities(?string $nextToken = null, int $pageSize = 100): array
    {
        return $this->transport->request('list_email_identities', 'GET', '/v2/email/identities', array_filter(['PageSize' => (string) $pageSize, 'NextToken' => $nextToken], fn ($v) => $v !== null));
    }

    /**
     * POST /v2/email/outbound-emails. Returns the SES MessageId.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sendEmail(array $payload): string
    {
        $response = $this->transport->request('send_email', 'POST', '/v2/email/outbound-emails', json: $payload, isSend: true);
        $id = $response['MessageId'] ?? null;
        if (! is_string($id) || $id === '') {
            throw ProviderError::schema('SES accepted the request but returned no MessageId.', 'send_email');
        }

        return $id;
    }
}
