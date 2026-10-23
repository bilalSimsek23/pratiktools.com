<?php

namespace App\Commerce\Contracts;

use App\Commerce\Data\ConsentChange;
use Carbon\CarbonImmutable;

/**
 * Optional: the store's own record of permission changes (evidence, not a
 * legal consent registry).
 */
interface ProvidesConsentHistory
{
    /**
     * @return list<ConsentChange>
     */
    public function consentHistory(CarbonImmutable $from, CarbonImmutable $to): array;
}
