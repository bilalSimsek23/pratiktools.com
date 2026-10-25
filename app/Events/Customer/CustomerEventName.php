<?php

namespace App\Events\Customer;

/**
 * Canonical, provider-independent event names produced in F1, with the
 * current schema version and the properties each version requires.
 */
enum CustomerEventName: string
{
    case CustomerCreated = 'CUSTOMER_CREATED';
    case CustomerUpdated = 'CUSTOMER_UPDATED';
    case OrderPlaced = 'ORDER_PLACED';
    case OrderStatusChanged = 'ORDER_STATUS_CHANGED';
    case CartUpdated = 'CART_UPDATED';
    // A newsletter/lead form submission with consent (store-side, not İYS).
    case NewsletterSubscribed = 'NEWSLETTER_SUBSCRIBED';

    public function schemaVersion(): int
    {
        return 1;
    }

    /**
     * @return list<string>
     */
    public function requiredProperties(): array
    {
        return match ($this) {
            self::CustomerCreated => ['source_kind'],
            self::CustomerUpdated => ['changed'],
            self::OrderPlaced => ['order_id', 'channel', 'grand_total', 'currency', 'item_count'],
            self::OrderStatusChanged => ['order_id', 'from_status', 'to_status'],
            self::CartUpdated => ['cart_id', 'item_count', 'total'],
            self::NewsletterSubscribed => ['form_id', 'placement'],
        };
    }
}
