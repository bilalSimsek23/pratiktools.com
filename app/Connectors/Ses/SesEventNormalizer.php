<?php

namespace App\Connectors\Ses;

use App\Email\Data\EmailEvent;
use App\Email\EmailEventType;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * SES event publishing records (eventType, or notificationType for plain
 * identity notifications) → canonical events. Only codes and counters are
 * kept: no headers, subjects, diagnostic texts or full link URLs (those
 * can contain personal data).
 */
final class SesEventNormalizer
{
    private const TYPES = [
        'Send' => EmailEventType::Sent,
        'Delivery' => EmailEventType::Delivered,
        'Bounce' => EmailEventType::Bounced,
        'Complaint' => EmailEventType::Complaint,
        'Reject' => EmailEventType::Rejected,
        'Open' => EmailEventType::Opened,
        'Click' => EmailEventType::Clicked,
        'DeliveryDelay' => EmailEventType::DeliveryDelayed,
        'Rendering Failure' => EmailEventType::RenderingFailed,
        'Subscription' => EmailEventType::SubscriptionChanged,
    ];

    /**
     * @param  array<string, mixed>  $record
     */
    public static function normalize(array $record): ?EmailEvent
    {
        $name = $record['eventType'] ?? $record['notificationType'] ?? null;
        $type = is_string($name) ? (self::TYPES[$name] ?? null) : null;
        $mail = is_array($record['mail'] ?? null) ? $record['mail'] : [];
        if ($type === null) {
            return null;
        }

        $messageId = is_string($mail['messageId'] ?? null) ? $mail['messageId'] : null;
        $detailKey = match ($type) {
            EmailEventType::Bounced => 'bounce',
            EmailEventType::Complaint => 'complaint',
            EmailEventType::Delivered => 'delivery',
            EmailEventType::Rejected => 'reject',
            EmailEventType::Opened => 'open',
            EmailEventType::Clicked => 'click',
            EmailEventType::DeliveryDelayed => 'deliveryDelay',
            EmailEventType::RenderingFailed => 'failure',
            EmailEventType::SubscriptionChanged => 'subscription',
            EmailEventType::Sent => 'send',
        };
        $detail = is_array($record[$detailKey] ?? null) ? $record[$detailKey] : [];

        $recipients = match ($type) {
            EmailEventType::Bounced => self::addresses($detail['bouncedRecipients'] ?? []),
            EmailEventType::Complaint => self::addresses($detail['complainedRecipients'] ?? []),
            EmailEventType::DeliveryDelayed => self::addresses($detail['delayedRecipients'] ?? []),
            EmailEventType::Delivered => array_values(array_filter((array) ($detail['recipients'] ?? []), 'is_string')),
            default => array_values(array_filter((array) ($mail['destination'] ?? []), 'is_string')),
        };
        $recipients = array_values(array_unique(array_map('strtolower', $recipients)));

        $bounceClass = null;
        $details = ['ses_event' => $name, 'pt_message_id' => self::tag($mail, 'pt_message_id'), 'pt_type' => self::tag($mail, 'pt_type')];
        switch ($type) {
            case EmailEventType::Bounced:
                $bounceType = (string) ($detail['bounceType'] ?? 'Undetermined');
                $bounceClass = match ($bounceType) {
                    'Permanent' => 'hard',
                    'Transient' => 'soft',
                    default => 'undetermined',
                };
                $details += ['bounce_type' => $bounceType, 'bounce_subtype' => (string) ($detail['bounceSubType'] ?? ''),
                    'status_codes' => self::statusCodes($detail['bouncedRecipients'] ?? [])];
                break;
            case EmailEventType::Complaint:
                $details += ['feedback_type' => $detail['complaintFeedbackType'] ?? null, 'complaint_subtype' => $detail['complaintSubType'] ?? null];
                break;
            case EmailEventType::Delivered:
                $details += ['processing_time_ms' => isset($detail['processingTimeMillis']) ? (int) $detail['processingTimeMillis'] : null];
                break;
            case EmailEventType::DeliveryDelayed:
                $details += ['delay_type' => $detail['delayType'] ?? null];
                break;
            case EmailEventType::Rejected:
                $details += ['reason' => $detail['reason'] ?? null];
                break;
            case EmailEventType::Opened:
                $details += ['bot' => $detail['isBotEvent'] ?? null];
                break;
            case EmailEventType::Clicked:
                $link = is_string($detail['link'] ?? null) ? parse_url($detail['link']) : null;
                $details += ['bot' => $detail['isBotEvent'] ?? null, 'link_host' => is_array($link) ? ($link['host'] ?? null) : null, 'link_path' => is_array($link) ? mb_substr((string) ($link['path'] ?? ''), 0, 200) : null];
                break;
            case EmailEventType::SubscriptionChanged:
                $details += ['unsubscribe_all' => (bool) (($detail['newTopicPreferences']['unsubscribeAll'] ?? false))];
                break;
            default:
                break;
        }

        $occurred = self::time($detail['timestamp'] ?? $mail['timestamp'] ?? null);
        $identity = $detail['feedbackId'] ?? $detail['timestamp'] ?? $mail['timestamp'] ?? '';
        $key = hash('sha256', implode('|', ['amazon_ses', $messageId, $type->value, (string) $identity, implode(',', $recipients)]));

        return new EmailEvent($type, 'amazon_ses', $messageId, $recipients, $occurred, $key, $bounceClass, array_filter($details, fn ($v) => $v !== null && $v !== '' && $v !== []));
    }

    /**
     * @return list<string>
     */
    private static function addresses(mixed $list): array
    {
        return array_values(array_filter(array_map(fn ($r) => is_array($r) && is_string($r['emailAddress'] ?? null) ? $r['emailAddress'] : null, (array) $list)));
    }

    /**
     * Enhanced status codes (e.g. 5.1.1) only; never the diagnostic text.
     *
     * @return list<string>
     */
    private static function statusCodes(mixed $list): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($r) => is_array($r) && is_string($r['status'] ?? null) && preg_match('/^\d\.\d{1,3}\.\d{1,3}$/', $r['status']) === 1 ? $r['status'] : null,
            (array) $list,
        ))));
    }

    /**
     * @param  array<string, mixed>  $mail
     */
    private static function tag(array $mail, string $name): ?string
    {
        $value = $mail['tags'][$name][0] ?? null;

        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) === 1 ? $value : null;
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        try {
            return is_string($value) ? CarbonImmutable::parse($value)->utc() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
