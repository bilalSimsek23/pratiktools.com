<?php

namespace App\Email;

use App\Audit\Actor;
use App\Consent\ConsentChannel;
use App\Consent\RecipientDirectory;
use App\Consent\SuppressionService;
use App\Consent\SuppressionSource;
use App\Email\Data\EmailEvent;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores canonical delivery events (one row per provider event and
 * recipient; duplicates are ignored) and applies the suppression policy.
 * PratikTools' own suppression list is the source of truth; the provider's
 * suppression list is never relied on.
 *
 * - hard bounce (permanent, incl. "already suppressed at the provider")
 *   → suppress (provider bounce source);
 * - soft bounce (transient) → counted; email.soft_bounce.threshold within
 *   the window → SOFT_BOUNCE suppression; undetermined → counted only;
 * - complaint → suppress (provider complaint source);
 * - everything else only updates the message's delivery status.
 */
final class EmailEventRecorder
{
    private const STATUS_RANK = ['delayed' => 1, 'delivered' => 2, 'rejected' => 3, 'bounced' => 4, 'complained' => 5];

    public function __construct(
        private readonly TenantContext $context,
        private readonly RecipientDirectory $recipients,
        private readonly SuppressionService $suppressions,
        private readonly EmailUsage $usage,
    ) {}

    /**
     * @return int new events stored
     */
    public function record(EmailEvent $event, ?string $inboxId = null): int
    {
        $tenantId = $this->context->requireTenant();
        $message = $this->message($event);
        $recipients = $event->recipients !== [] ? $event->recipients : ($message !== null ? [$message->to_email] : []);
        $new = 0;

        foreach ($recipients === [] ? [null] : $recipients as $address) {
            $hash = $address === null ? null : self::hash($address);
            $inserted = DB::affectingStatement(
                'INSERT INTO email_events (id, tenant_id, message_id, provider, provider_message_id, event_type, event_key, recipient_hash, bounce_class, details, occurred_at, received_at, webhook_inbox_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, now(), ?) ON CONFLICT (tenant_id, provider, event_key) DO NOTHING',
                [(string) Str::uuid7(), $tenantId, $message?->id, $event->provider, $event->providerMessageId, $event->type->value,
                    hash('sha256', $event->eventKey.'|'.$hash), $hash, $event->bounceClass, json_encode((object) $event->details), $event->occurredAt ?? now(), $inboxId],
            );
            if ($inserted === 0) {
                continue;
            }
            $new++;

            $this->updateMessage($message, $event);
            $this->usage->add($event->provider, match ($event->type) {
                EmailEventType::Delivered => ['emails_delivered' => 1],
                EmailEventType::Bounced => ['emails_bounced' => 1],
                EmailEventType::Complaint => ['complaints' => 1],
                default => [],
            });
            if ($address !== null) {
                $this->applySuppression($event, $address, $message?->recipient_id, $hash);
            }
        }

        return $new;
    }

    public static function hash(string $address): string
    {
        return hash_hmac('sha256', strtolower(trim($address)), (string) config('app.key'));
    }

    private function message(EmailEvent $event): ?object
    {
        $tenantId = $this->context->requireTenant();
        if ($event->providerMessageId !== null) {
            $row = DB::table('email_messages')->where('tenant_id', $tenantId)->where('provider', $event->provider)->where('provider_message_id', $event->providerMessageId)->first();
            if ($row !== null) {
                return $row;
            }
        }
        $ours = $event->details['pt_message_id'] ?? null;

        return is_string($ours) && Str::isUuid($ours) ? DB::table('email_messages')->where('tenant_id', $tenantId)->where('id', $ours)->first() : null;
    }

    private function updateMessage(?object $message, EmailEvent $event): void
    {
        $status = $event->type->deliveryStatus();
        if ($message === null || $status === null) {
            return;
        }
        // Never downgrade (a late "delivered" does not hide a complaint).
        $current = self::STATUS_RANK[$message->delivery_status ?? ''] ?? 0;
        $values = ['last_event_at' => now(), 'updated_at' => now()];
        if (self::STATUS_RANK[$status] >= $current) {
            $values['delivery_status'] = $status;
        }
        DB::table('email_messages')->where('tenant_id', $this->context->requireTenant())->where('id', $message->id)->update($values);
    }

    private function applySuppression(EmailEvent $event, string $address, ?string $recipientId, string $hash): void
    {
        [$source, $reason] = match (true) {
            $event->type === EmailEventType::Complaint => [SuppressionSource::providerComplaint($event->provider), 'complaint'],
            $event->type === EmailEventType::Bounced && $event->bounceClass === 'hard' => [SuppressionSource::providerBounce($event->provider),
                'hard bounce '.($event->details['bounce_type'] ?? '').'/'.($event->details['bounce_subtype'] ?? '')],
            $event->type === EmailEventType::Bounced && $event->bounceClass === 'soft' && $this->softBounces($hash) >= (int) config('email.soft_bounce.threshold') => [SuppressionSource::SoftBounce, 'repeated soft bounces'],
            default => [null, null],
        };
        if ($source === null) {
            return;
        }

        if ($recipientId === null) {
            $normalized = $this->recipients->email($address);
            $recipientId = $normalized === null ? null : ($this->recipients->ensure('email', [$normalized])[$normalized['address']] ?? null);
        }
        if ($recipientId !== null) {
            $this->suppressions->suppress($recipientId, $source, ConsentChannel::Email, $reason, Actor::system('email_events'));
        }
    }

    private function softBounces(string $hash): int
    {
        return DB::table('email_events')->where('tenant_id', $this->context->requireTenant())->where('recipient_hash', $hash)
            ->where('event_type', EmailEventType::Bounced->value)->where('bounce_class', 'soft')
            ->where('occurred_at', '>=', now()->subDays((int) config('email.soft_bounce.window_days')))->count();
    }
}
