<?php

namespace App\Http\Controllers;

use App\Integrations\ProviderRegistry;
use App\Tenancy\TenantDirectory;
use App\Webhooks\WebhookInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Generic webhook receiver: POST /webhooks/{provider}/{tenantPublicId}.
 * The tenant comes from the opaque public id in the URL (resolved through
 * the tenant directory) and, once connectors exist, is confirmed by the
 * provider's signature verifier. Payloads are stored, never processed
 * inline.
 */
final class WebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        string $tenantPublicId,
        ProviderRegistry $providers,
        TenantDirectory $directory,
        WebhookInbox $inbox,
    ): JsonResponse {
        abort_unless($providers->isKnown($provider), 404);

        $tenantId = $directory->idByPublicId($tenantPublicId);
        abort_if($tenantId === null, 404);

        $raw = $request->getContent();
        abort_if(strlen($raw) > (int) config('platform.webhooks.max_payload_bytes'), 413);

        $payload = json_decode($raw, true);
        abort_unless(is_array($payload), 400);

        $verifier = $providers->signatureVerifier($provider);
        $signatureStatus = $verifier?->verify($request, $tenantId) ?? 'not_configured';
        abort_if(in_array($signatureStatus, ['invalid', 'missing'], true), 401);

        $result = $inbox->accept(
            tenantId: $tenantId,
            provider: $provider,
            eventType: $this->stringOrNull($payload['type'] ?? $payload['event'] ?? $request->header('X-Event-Type')),
            externalEventId: $this->stringOrNull($request->header('X-Webhook-Id') ?? $payload['event_id'] ?? $payload['id'] ?? null),
            payload: $payload,
            headers: $this->minimizedHeaders($request),
            signatureStatus: $signatureStatus,
        );

        return $result['duplicate']
            ? response()->json(['status' => 'duplicate'])
            : response()->json(['status' => 'accepted'], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function minimizedHeaders(Request $request): array
    {
        $kept = [];

        foreach (config('platform.webhooks.stored_headers') as $name) {
            if ($request->headers->has($name)) {
                $kept[$name] = mb_substr((string) $request->headers->get($name), 0, 255);
            }
        }

        $kept['has_signature'] = $request->headers->has('X-Signature') || $request->headers->has('Authorization');

        return $kept;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? mb_substr((string) $value, 0, 191) : null;
    }
}
