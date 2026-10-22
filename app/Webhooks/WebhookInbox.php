<?php

namespace App\Webhooks;

use App\Jobs\ProcessWebhook;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores an incoming webhook before anything else happens, then queues it.
 * The unique (provider, tenant, external_event_id) index makes redelivery of
 * the same event a no-op.
 */
final class WebhookInbox
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     * @return array{id: ?string, duplicate: bool}
     */
    public function accept(
        string $tenantId,
        string $provider,
        ?string $eventType,
        ?string $externalEventId,
        array $payload,
        array $headers,
        string $signatureStatus,
    ): array {
        $id = $this->context->run($tenantId, function () use ($tenantId, $provider, $eventType, $externalEventId, $payload, $headers, $signatureStatus) {
            $row = DB::selectOne(
                <<<'SQL'
                    INSERT INTO webhook_inbox
                        (id, tenant_id, provider, event_type, external_event_id, payload, headers_minimized, signature_status, processing_status, received_at, attempts)
                    VALUES (?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?, 'pending', now(), 0)
                    ON CONFLICT (provider, (COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000'::uuid)), external_event_id)
                        WHERE external_event_id IS NOT NULL
                    DO NOTHING
                    RETURNING id
                SQL,
                [
                    (string) Str::uuid7(),
                    $tenantId,
                    $provider,
                    $eventType,
                    $externalEventId,
                    json_encode($payload, JSON_THROW_ON_ERROR),
                    json_encode($headers, JSON_THROW_ON_ERROR),
                    $signatureStatus,
                ],
            );

            return $row?->id;
        });

        if ($id === null) {
            return ['id' => null, 'duplicate' => true];
        }

        ProcessWebhook::dispatch($tenantId, (string) $id);

        return ['id' => (string) $id, 'duplicate' => false];
    }
}
