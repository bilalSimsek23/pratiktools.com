<?php

namespace App\Consent\Reconciliation;

/**
 * Reads one İYS change report format. Implementations must follow an
 * official format specification or a real sample provided by the
 * operator; none is registered until then (consent.reconciliation.parsers).
 *
 * Rows are produced lazily (generator) so a large report is never held in
 * memory. A file that is not of the format at all throws
 * ReconciliationError; a single unreadable row is an
 * InvalidReconciliationRow.
 */
interface IysReconciliationParser
{
    /**
     * @return iterable<int, IysConsentChange|InvalidReconciliationRow>
     */
    public function parse(ReconciliationFile $file): iterable;
}
