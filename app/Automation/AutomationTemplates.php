<?php

namespace App\Automation;

use InvalidArgumentException;

/**
 * Ready-made definitions. Every timing is a parameter, never hard-coded
 * in the engine.
 */
final class AutomationTemplates
{
    /**
     * @param  array<string, mixed>  $options
     * @return array{trigger: array<string, mixed>, steps: list<array<string, mixed>>, settings: array<string, mixed>}
     */
    public static function make(string $template, array $options = []): array
    {
        return match ($template) {
            'abandoned_cart' => self::abandonedCart((int) ($options['delay_minutes'] ?? 120), $options['template_version_id'] ?? null),
            'winback' => self::winback((int) ($options['days'] ?? 90), $options['template_version_id'] ?? null),
            default => throw new InvalidArgumentException('Unknown template (abandoned_cart, winback).'),
        };
    }

    /**
     * Cart activity → wait → exit if the customer ordered or the cart is
     * gone → items still sellable → marketing eligibility → frequency cap
     * → e-mail intent, else push intent. Prices and stock are not copied
     * into the intent: they are references resolved at dispatch time.
     *
     * @return array{trigger: array<string, mixed>, steps: list<array<string, mixed>>, settings: array<string, mixed>}
     */
    public static function abandonedCart(int $delayMinutes, ?string $templateVersionId = null): array
    {
        return [
            'trigger' => ['type' => TriggerType::CartAbandoned->value, 'params' => []],
            'steps' => [
                ['key' => 'wait', 'type' => 'delay', 'params' => ['minutes' => $delayMinutes]],
                ['key' => 'not_ordered', 'type' => 'condition', 'params' => ['check' => 'no_order_since_trigger']],
                ['key' => 'cart_open', 'type' => 'condition', 'params' => ['check' => 'cart_still_open']],
                ['key' => 'items_available', 'type' => 'condition', 'params' => ['check' => 'cart_items_available']],
                ['key' => 'email', 'type' => 'create_email_intent', 'params' => array_filter(['message_type' => 'marketing', 'on_ineligible' => 'continue', 'template_version_id' => $templateVersionId])],
                ['key' => 'push', 'type' => 'create_push_intent', 'params' => ['message_type' => 'marketing', 'on_ineligible' => 'continue', 'only_if_no_intent' => true]],
            ],
            'settings' => ['message_type' => 'marketing'],
        ];
    }

    /**
     * "Seni özledik": last successful order X days ago, nothing since →
     * marketing e-mail intent, else push.
     *
     * @return array{trigger: array<string, mixed>, steps: list<array<string, mixed>>, settings: array<string, mixed>}
     */
    public static function winback(int $days, ?string $templateVersionId = null): array
    {
        return [
            'trigger' => ['type' => TriggerType::NoPurchaseFor->value, 'params' => ['days' => $days, 'catch_up_days' => 7]],
            'steps' => [
                ['key' => 'still_inactive', 'type' => 'condition', 'params' => ['check' => 'no_order_since_trigger']],
                ['key' => 'email', 'type' => 'create_email_intent', 'params' => array_filter(['message_type' => 'marketing', 'on_ineligible' => 'continue', 'template_version_id' => $templateVersionId])],
                ['key' => 'push', 'type' => 'create_push_intent', 'params' => ['message_type' => 'marketing', 'on_ineligible' => 'continue', 'only_if_no_intent' => true]],
            ],
            'settings' => ['message_type' => 'marketing'],
        ];
    }
}
