<?php

namespace App\Connectors\Ses;

use App\Email\EmailEventRecorder;
use App\Models\TenantIntegration;
use App\Models\WebhookInboxItem;
use App\Webhooks\Contracts\WebhookHandler;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;

/**
 * Processes verified SNS deliveries from the webhook inbox (async, on the
 * webhooks queue). Notifications carry one SES event record in Message.
 * A SubscriptionConfirmation is recorded as pending and confirmed only on
 * request (email:ses confirm-subscription), unless auto_confirm is set.
 */
final class SesWebhookHandler implements WebhookHandler
{
    public function __construct(
        private readonly EmailEventRecorder $recorder,
        private readonly Http $http,
    ) {}

    public function handle(WebhookInboxItem $item): void
    {
        $payload = (array) $item->payload;

        match ($payload['Type'] ?? null) {
            'Notification' => $this->notification($payload, $item->id),
            'SubscriptionConfirmation' => $this->subscription($payload, $item->id),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function notification(array $payload, string $inboxId): void
    {
        $record = json_decode((string) ($payload['Message'] ?? ''), true);
        $event = is_array($record) ? SesEventNormalizer::normalize($record) : null;
        if ($event === null) {
            Log::info('ses.event_ignored', ['inbox_id' => $inboxId]);

            return;
        }

        $this->recorder->record($event, $inboxId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function subscription(array $payload, string $inboxId): void
    {
        $integration = TenantIntegration::query()->where('provider', 'amazon_ses')->where('capability', 'email')->first();
        $url = (string) ($payload['SubscribeURL'] ?? '');
        if ($integration === null || ! self::trustedSubscribeUrl($url)) {
            return;
        }

        $confirm = (bool) config('email.ses.sns.auto_confirm');
        if ($confirm) {
            $this->http->timeout(10)->get($url)->throw();
        }
        $integration->forceFill(['settings' => ['sns_subscription' => [
            'topic_arn' => $payload['TopicArn'] ?? null, 'state' => $confirm ? 'confirmed' : 'pending_confirmation', 'inbox_id' => $inboxId, 'at' => now()->toIso8601String(),
        ]] + (array) $integration->settings])->save();
    }

    public static function trustedSubscribeUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ! isset($parts['user'], $parts['port'])
            && preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/', (string) ($parts['host'] ?? '')) === 1
            && str_contains((string) ($parts['query'] ?? ''), 'Action=ConfirmSubscription');
    }
}
