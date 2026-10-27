<?php

namespace App\Consent\Reconciliation;

/**
 * A report row that could not be read; only its number and a reason code
 * are kept (never the row text).
 */
final readonly class InvalidReconciliationRow
{
    public function __construct(public int $rowNumber, public string $code) {}
}
