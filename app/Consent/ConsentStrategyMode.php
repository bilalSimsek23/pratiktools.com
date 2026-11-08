<?php

namespace App\Consent;

/**
 * How a tenant learns e-mail marketing consent: by querying the İYS API
 * (F2), from ViA events plus İYS daily change reports (F2-R), or from its
 * own store permissions after an operator attested that they are valid
 * commercial message consents (merchant_verified). The last one is not an
 * İYS or ViA verification and is never shown as one.
 */
enum ConsentStrategyMode: string
{
    case DirectApi = 'direct_api';
    case ViaReconciliation = 'via_reconciliation';
    case MerchantVerified = 'merchant_verified';

    public function label(): string
    {
        return match ($this) {
            self::DirectApi => 'İYS API (doğrudan)',
            self::ViaReconciliation => 'ViA + Günlük mutabakat',
            self::MerchantVerified => 'Mağaza izni (operasyonel doğrulama, İYS doğrulaması değil)',
        };
    }
}
