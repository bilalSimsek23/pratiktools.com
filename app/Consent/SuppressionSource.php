<?php

namespace App\Consent;

enum SuppressionSource: string
{
    case Manual = 'MANUAL';
    case KlaviyoUnsubscribe = 'KLAVIYO_UNSUBSCRIBE';
    case KlaviyoBounce = 'KLAVIYO_BOUNCE';
    case KlaviyoSpam = 'KLAVIYO_SPAM';
    case IysRet = 'IYS_RET';
    case InvalidEmail = 'INVALID_EMAIL';
    case System = 'SYSTEM';
    case SesBounce = 'SES_BOUNCE';
    case SesComplaint = 'SES_COMPLAINT';
    case Unsubscribe = 'UNSUBSCRIBE';
    // Repeated transient bounces (provider independent).
    case SoftBounce = 'SOFT_BOUNCE';

    /**
     * Source for a hard bounce reported by an e-mail provider.
     */
    public static function providerBounce(string $provider): self
    {
        return match ($provider) {
            'amazon_ses' => self::SesBounce,
            'klaviyo' => self::KlaviyoBounce,
            default => self::System,
        };
    }

    /**
     * Source for a spam complaint reported by an e-mail provider.
     */
    public static function providerComplaint(string $provider): self
    {
        return match ($provider) {
            'amazon_ses' => self::SesComplaint,
            'klaviyo' => self::KlaviyoSpam,
            default => self::System,
        };
    }
}
