<?php

namespace App\Push;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Connectors\OneSignal\OneSignalClientFactory;
use App\Integrations\Errors\ProviderError;
use App\Jobs\SendPush;
use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Requests and performs guarded pushes. A request is a push_send_requests
 * row with its own idempotency key; the queued send re-checks the guard at
 * send time, skips a row that already finished (duplicate delivery), and
 * reuses the key on every provider retry.
 */
final class PushDelivery
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PushSendGuard $guard,
        private readonly OneSignalClientFactory $clients,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $subscriptionIds
     */
    public function requestTestPush(TenantIntegration $integration, array $subscriptionIds, PushMessage $message, Actor $actor): string
    {
        $key = (string) Str::uuid();
        // Fail fast; checked again when the job sends.
        $this->guard->authorize($integration, $subscriptionIds, $key);

        $id = (string) Str::uuid7();
        DB::table('push_send_requests')->insert([
            'id' => $id, 'tenant_id' => $this->context->requireTenant(), 'integration_id' => $integration->id, 'idempotency_key' => $key,
            'mode' => $this->guard->mode()->value, 'status' => 'requested', 'recipient_count' => count($subscriptionIds),
            'recipients' => json_encode($subscriptionIds), 'message' => json_encode($this->encode($message)),
            'requested_by' => $actor->type.($actor->id !== null ? ':'.$actor->id : ''), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record(AuditAction::PushSendRequested, 'push_send_request', $id, ['recipients' => count($subscriptionIds), 'mode' => $this->guard->mode()->value] + $message->summary(), $actor);

        SendPush::dispatch($id, $this->context->requireTenant());

        return $id;
    }

    public function send(string $requestId, Actor $actor): string
    {
        $request = DB::transaction(function () use ($requestId) {
            $row = DB::table('push_send_requests')->where('tenant_id', $this->context->requireTenant())->where('id', $requestId)->lockForUpdate()->first();
            if ($row === null || ! in_array($row->status, ['requested', 'failed'], true)) {
                return null;
            }
            DB::table('push_send_requests')->where('id', $row->id)->update(['status' => 'sending', 'attempts' => $row->attempts + 1, 'updated_at' => now()]);

            return $row;
        });
        if ($request === null) {
            return 'skipped';
        }

        $integration = TenantIntegration::query()->findOrFail($request->integration_id);
        $recipients = json_decode($request->recipients, true);
        $message = $this->decode(json_decode($request->message, true));

        try {
            $authorization = $this->guard->authorize($integration, $recipients, $request->idempotency_key);
            $result = $this->clients->push($integration, $actor)->send($authorization, $message, $recipients);
        } catch (PushSendBlocked $e) {
            $this->finish($request->id, 'blocked', null, $e->reason);
            $this->audit->record(AuditAction::PushBlocked, 'push_send_request', $request->id, ['reason' => $e->reason], $actor);

            return 'blocked';
        } catch (Throwable $e) {
            $code = $e instanceof ProviderError ? $e->category : class_basename($e);
            $this->finish($request->id, 'failed', null, $code);
            $this->audit->record(AuditAction::PushFailed, 'push_send_request', $request->id, ['error' => $code], $actor);

            return 'failed';
        }

        if ($result['id'] === null) {
            $this->finish($request->id, 'not_sent', null, mb_substr(implode('; ', $result['errors']), 0, 64) ?: 'not_sent');
            $this->audit->record(AuditAction::PushNotSent, 'push_send_request', $request->id, ['errors' => count($result['errors'])], $actor);

            return 'not_sent';
        }

        $this->finish($request->id, 'sent', $result['id'], null);
        $this->audit->record(AuditAction::PushSent, 'push_send_request', $request->id, ['provider_message_id' => $result['id'], 'recipients' => count($recipients)], $actor);

        return 'sent';
    }

    private function finish(string $id, string $status, ?string $providerId, ?string $error): void
    {
        DB::table('push_send_requests')->where('tenant_id', $this->context->requireTenant())->where('id', $id)->update([
            'status' => $status, 'provider_message_id' => $providerId, 'error_code' => $error, 'sent_at' => $status === 'sent' ? now() : null, 'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function encode(PushMessage $m): array
    {
        return ['title' => $m->title, 'body' => $m->body, 'image_url' => $m->imageUrl, 'web_url' => $m->webUrl, 'deep_link' => $m->deepLink,
            'campaign_id' => $m->campaignId, 'automation_run_id' => $m->automationRunId, 'metadata' => $m->metadata];
    }

    /**
     * @param  array<string, mixed>  $m
     */
    private function decode(array $m): PushMessage
    {
        return new PushMessage((string) $m['title'], (string) $m['body'], $m['image_url'] ?? null, $m['web_url'] ?? null, $m['deep_link'] ?? null,
            $m['campaign_id'] ?? null, $m['automation_run_id'] ?? null, (array) ($m['metadata'] ?? []));
    }
}
