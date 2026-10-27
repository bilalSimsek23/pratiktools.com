<?php

namespace App\Consent;

/**
 * How a tenant learns İYS consent: by querying the İYS API (F2), or from
 * ViA events plus İYS daily change reports (F2-R).
 */
enum ConsentStrategyMode: string
{
    case DirectApi = 'direct_api';
    case ViaReconciliation = 'via_reconciliation';

    public function label(): string
    {
        return match ($this) {
            self::DirectApi => 'İYS API (doğrudan)',
            self::ViaReconciliation => 'ViA + Günlük mutabakat',
        };
    }
}
