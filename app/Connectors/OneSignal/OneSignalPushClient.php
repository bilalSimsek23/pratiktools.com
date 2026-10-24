<?php

namespace App\Connectors\OneSignal;

use App\Integrations\Errors\ProviderError;
use App\Push\PushMessage;
use App\Push\PushSendBlocked;
use App\Push\PushSendingMode;
use App\Push\SendAuthorization;

/**
 * POST /notifications?c=push. Sends only to subscription ids authorized
 * by PushSendGuard, with the authorization's idempotency key (reused on
 * every retry, so a retry never sends twice). Re-checks the kill switch
 * itself: with the platform mode off no request is ever built.
 */
final class OneSignalPushClient
{
    public function __construct(private readonly OneSignalTransport $transport) {}

    /**
     * @param  list<string>  $subscriptionIds
     * @return array{id: ?string, errors: list<string>}
     */
    public function send(SendAuthorization $authorization, PushMessage $message, array $subscriptionIds): array
    {
        if (PushSendingMode::platform() === PushSendingMode::Off || $authorization->mode === PushSendingMode::Off) {
            throw new PushSendBlocked('SENDING_MODE_OFF', 'Push sending is off.');
        }
        if ($authorization->integrationId !== $this->transport->credentials()->integrationId) {
            throw new PushSendBlocked('AUTHORIZATION_MISMATCH', 'The send authorization belongs to another integration.');
        }
        $subscriptionIds = array_values(array_unique($subscriptionIds));
        if ($subscriptionIds === [] || array_diff($subscriptionIds, $authorization->subscriptionIds) !== []) {
            throw new PushSendBlocked('RECIPIENT_NOT_AUTHORIZED', 'A recipient is not covered by the send authorization.');
        }

        $body = $this->transport->request('send_push', 'onesignal_send', 'POST', '/notifications', ['c' => 'push'], array_filter([
            'app_id' => $this->transport->credentials()->appId,
            'target_channel' => 'push',
            'include_subscription_ids' => $subscriptionIds,
            'headings' => ['en' => $message->title, 'tr' => $message->title],
            'contents' => ['en' => $message->body, 'tr' => $message->body],
            'big_picture' => $message->imageUrl,
            'ios_attachments' => $message->imageUrl !== null ? ['image' => $message->imageUrl] : null,
            'web_url' => $message->webUrl,
            'app_url' => $message->deepLink,
            'data' => array_filter(['campaign_id' => $message->campaignId, 'automation_run_id' => $message->automationRunId] + $message->metadata, fn ($v) => $v !== null),
            'idempotency_key' => $authorization->idempotencyKey,
        ], fn ($v) => $v !== null && $v !== []));

        if (! array_key_exists('id', $body)) {
            throw ProviderError::schema('OneSignal send response has no id.', 'send_push');
        }

        return [
            'id' => is_string($body['id']) && $body['id'] !== '' ? $body['id'] : null,
            'errors' => array_values(array_filter((array) ($body['errors'] ?? []), 'is_string')),
        ];
    }
}
