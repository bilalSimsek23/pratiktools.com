<?php

namespace App\Consent;

/**
 * Where an observation came from. Only official İYS records (the İYS API,
 * a ViA process, an İYS change report) are authoritative for commercial
 * electronic messages; every other source is evidence.
 *
 * Stored values are kept as they were; category() names the source in the
 * F2-R vocabulary.
 */
enum ConsentSourceProvider: string
{
    case Iys = 'IYS';
    // A verified ViA (İYS consent frame / ViA Ret) completion event.
    case Via = 'VIA';
    // A row of an applied İYS daily change report.
    case IysReconciliation = 'IYS_RECONCILIATION';
    case Ticimax = 'TICIMAX';
    case TicimaxNewsletter = 'TICIMAX_NEWSLETTER';
    case LeadForm = 'LEAD_FORM';
    case Manual = 'MANUAL';
    case OneSignal = 'ONESIGNAL';
    case Klaviyo = 'KLAVIYO';
    // The recipient's own action on PratikTools (unsubscribe link).
    case PratikTools = 'PRATIKTOOLS';

    public const OFFICIAL = ['IYS', 'VIA', 'IYS_RECONCILIATION'];

    public function isAuthoritative(): bool
    {
        return in_array($this->value, self::OFFICIAL, true);
    }

    public function category(): string
    {
        return match ($this) {
            self::Iys => 'DIRECT_API',
            self::Via => 'VIA',
            self::IysReconciliation => 'IYS_RECONCILIATION',
            self::PratikTools => 'LOCAL_UNSUBSCRIBE',
            self::Ticimax, self::TicimaxNewsletter => 'LEGACY_TICIMAX',
            self::LeadForm => 'LEAD_FORM',
            self::Manual => 'OPERATOR_IMPORT',
            self::OneSignal, self::Klaviyo => 'LEGACY_PROVIDER',
        };
    }
}
