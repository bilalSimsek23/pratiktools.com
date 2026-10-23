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
}
