<?php

namespace App\Email;

/**
 * Canonical delivery events. Providers translate their own event names
 * into these; the core never sees provider event names.
 */
enum EmailEventType: string
{
    case Sent = 'EMAIL_SENT';
    case Delivered = 'EMAIL_DELIVERED';
    case Bounced = 'EMAIL_BOUNCED';
    case Complaint = 'EMAIL_COMPLAINT';
    case Rejected = 'EMAIL_REJECTED';
    case Opened = 'EMAIL_OPENED';
    case Clicked = 'EMAIL_CLICKED';
    case DeliveryDelayed = 'EMAIL_DELIVERY_DELAYED';
    case RenderingFailed = 'EMAIL_RENDERING_FAILED';
    case SubscriptionChanged = 'EMAIL_SUBSCRIPTION_CHANGED';

    /**
     * delivery_status on the message after this event (null: unchanged).
     */
    public function deliveryStatus(): ?string
    {
        return match ($this) {
            self::Delivered => 'delivered',
            self::Bounced => 'bounced',
            self::Complaint => 'complained',
            self::Rejected => 'rejected',
            self::DeliveryDelayed => 'delayed',
            default => null,
        };
    }
}
