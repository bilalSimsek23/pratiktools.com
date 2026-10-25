<?php

namespace App\Automation;

enum TriggerType: string
{
    // Event based (from customer_events, consent_events, push_subscriptions).
    case CartAbandoned = 'cart_abandoned';
    case OrderCreated = 'order_created';
    case OrderCompleted = 'order_completed';
    case CustomerCreated = 'customer_created';
    case NewsletterSubscribed = 'newsletter_subscribed';
    case ConsentChanged = 'consent_changed';
    case PushSubscriptionChanged = 'push_subscription_changed';
    // Time based (evaluated periodically).
    case NoPurchaseFor = 'no_purchase_for';
    case SegmentPeriodic = 'segment_periodic';

    public function isTimeBased(): bool
    {
        return in_array($this, [self::NoPurchaseFor, self::SegmentPeriodic], true);
    }

    public function source(): ?string
    {
        return match ($this) {
            self::CartAbandoned, self::OrderCreated, self::OrderCompleted, self::CustomerCreated, self::NewsletterSubscribed => 'customer_events',
            self::ConsentChanged => 'consent_events',
            self::PushSubscriptionChanged => 'push_subscriptions',
            default => null,
        };
    }
}
