<?php

namespace App\Email;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Connectors\Ses\SesWebhookHandler;
use App\Models\TenantIntegration;
use App\Models\WebhookInboxItem;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The SES → SNS → webhook pipeline of the current tenant: what the panel
 * shows (subscription state, last webhook, last SES event) and the explicit
 * "Aboneliği Onayla" (email:ses confirm-subscription).
 *
 * The SubscribeURL carries a confirmation token: it stays in the webhook
 * inbox, is only ever requested (GET, AWS SNS hosts only) and never shown,
 * logged or audited; neither are request errors, which can quote it.
 */
final class SnsSubscriptions
{
    /** SNS confirmation tokens expire after three days. */
    public const TOKEN_TTL_HOURS = 72;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly Http $http,
    ) {}

    /**
     * @return array{state: string, topic_arn: ?string, topic_allowed: bool, at: ?string, expired: bool, error: ?string, last_webhook: ?array<string, mixed>, last_event: ?array<string, mixed>, invalid_7d: int}
     */
    public function state(TenantIntegration $integration): array
    {
        $tenantId = $this->context->requireTenant();
        $s = (array) ($integration->settings['sns_subscription'] ?? []);
        $state = is_string($s['state'] ?? null) ? $s['state'] : 'none';
        $at = is_string($s['at'] ?? null) ? $s['at'] : null;
        $topic = is_string($s['topic_arn'] ?? null) ? $s['topic_arn'] : null;

        $webhook = DB::table('webhook_inbox')->where('tenant_id', $tenantId)->where('provider', 'amazon_ses')->orderByDesc('received_at')
            ->first(['event_type', 'signature_status', 'processing_status', 'received_at']);
        $event = DB::table('email_events')->where('tenant_id', $tenantId)->where('provider', 'amazon_ses')->orderByDesc('received_at')
            ->first(['event_type', 'bounce_class', 'occurred_at', 'received_at']);

        return [
            'state' => $state,
            'topic_arn' => $topic,
            'topic_allowed' => $topic !== null && in_array($topic, (array) ($integration->settings['sns_topic_arns'] ?? []), true),
            'at' => $at,
            'expired' => $state === 'pending_confirmation' && $at !== null && CarbonImmutable::parse($at)->addHours(self::TOKEN_TTL_HOURS)->isPast(),
            'error' => is_string($s['error'] ?? null) ? $s['error'] : null,
            'last_webhook' => $webhook === null ? null : (array) $webhook,
            'last_event' => $event === null ? null : (array) $event,
            'invalid_7d' => DB::table('webhook_inbox')->where('tenant_id', $tenantId)->where('provider', 'amazon_ses')
                ->where('signature_status', '!=', 'valid')->where('received_at', '>=', now()->subDays(7))->count(),
        ];
    }

    /**
     * Confirms the pending subscription. A failure is stored as state
     * "error" (with a safe reason) and reported as SnsConfirmationFailed.
     *
     * @throws SnsConfirmationFailed
     */
    public function confirm(TenantIntegration $integration, Actor $actor): void
    {
        $pending = (array) ($integration->settings['sns_subscription'] ?? []);
        if (! in_array($pending['state'] ?? null, ['pending_confirmation', 'error'], true) || ! isset($pending['inbox_id'])) {
            throw new SnsConfirmationFailed('NO_PENDING_SUBSCRIPTION');
        }
        if (! in_array($pending['topic_arn'] ?? null, (array) ($integration->settings['sns_topic_arns'] ?? []), true)) {
            throw new SnsConfirmationFailed('TOPIC_NOT_ALLOWED');
        }
        $item = WebhookInboxItem::query()->where('provider', 'amazon_ses')->where('signature_status', 'valid')->find($pending['inbox_id']);
        $url = (string) ($item?->payload['SubscribeURL'] ?? '');
        if (! SesWebhookHandler::trustedSubscribeUrl($url)) {
            throw new SnsConfirmationFailed('UNTRUSTED_SUBSCRIBE_URL');
        }

        $reason = null;
        try {
            $response = $this->http->timeout(10)->withoutRedirecting()->get($url);
            if (! $response->successful()) {
                $reason = 'AWS_REJECTED_HTTP_'.$response->status();
            }
        } catch (Throwable) {
            // Never the exception message: it can quote the URL and its token.
            $reason = 'AWS_UNREACHABLE';
        } finally {
            unset($url);
        }

        $base = array_intersect_key($pending, array_flip(['topic_arn', 'inbox_id']));
        $now = now()->toIso8601String();
        if ($reason !== null) {
            $integration->forceFill(['settings' => ['sns_subscription' => $base + ['state' => 'error', 'error' => $reason, 'at' => $pending['at'] ?? $now, 'failed_at' => $now]] + (array) $integration->settings])->save();
            $this->audit->record(AuditAction::EmailSnsSubscriptionFailed, 'tenant_integration', $integration->id, ['topic_arn' => $base['topic_arn'] ?? null, 'reason' => $reason], $actor);

            throw new SnsConfirmationFailed($reason);
        }

        $integration->forceFill(['settings' => ['sns_subscription' => $base + ['state' => 'confirmed', 'at' => $now]] + (array) $integration->settings])->save();
        $this->audit->record(AuditAction::EmailSnsSubscriptionConfirmed, 'tenant_integration', $integration->id, ['topic_arn' => $base['topic_arn'] ?? null], $actor);
    }
}
