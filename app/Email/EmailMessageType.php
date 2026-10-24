<?php

namespace App\Email;

/**
 * Marketing and transactional mail follow different rules:
 * - marketing: İYS consent (Consent Engine), suppressions, frequency cap,
 *   unsubscribe link and deliverability guard rails;
 * - transactional: no marketing consent (the customer's own order or
 *   account), but hard bounces and manual blocks still stop it;
 * - test: only ever to active test recipients, never to customers.
 */
enum EmailMessageType: string
{
    case Marketing = 'marketing';
    case Transactional = 'transactional';
    case Test = 'test';

    public function requiresMarketingConsent(): bool
    {
        return $this === self::Marketing;
    }

    public function requiresUnsubscribe(): bool
    {
        return $this === self::Marketing;
    }

    /**
     * Suppression sources that stop this type. Address problems (bounces,
     * invalid, manual) stop everything; opt-outs and spam complaints stop
     * marketing only (the customer's own order or password mail still
     * goes out).
     *
     * @return list<string>
     */
    public function blockingSuppressionSources(): array
    {
        $always = ['MANUAL', 'INVALID_EMAIL', 'SYSTEM', 'SES_BOUNCE', 'KLAVIYO_BOUNCE', 'SOFT_BOUNCE'];

        return $this === self::Transactional
            ? $always
            : [...$always, 'SES_COMPLAINT', 'KLAVIYO_SPAM', 'UNSUBSCRIBE', 'KLAVIYO_UNSUBSCRIBE', 'IYS_RET'];
    }
}
