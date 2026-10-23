<?php

namespace App\Consent;

/**
 * Where an observation came from. Only İYS is authoritative for commercial
 * electronic messages; every other source is evidence.
 */
enum ConsentSourceProvider: string
{
    case Iys = 'IYS';
    case Ticimax = 'TICIMAX';
    case TicimaxNewsletter = 'TICIMAX_NEWSLETTER';
    case LeadForm = 'LEAD_FORM';
    case Manual = 'MANUAL';
    case OneSignal = 'ONESIGNAL';
    case Klaviyo = 'KLAVIYO';

    public function isAuthoritative(): bool
    {
        return $this === self::Iys;
    }
}
