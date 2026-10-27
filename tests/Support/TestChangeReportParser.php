<?php

namespace Tests\Support;

use App\Consent\ConsentChannel;
use App\Consent\ConsentStatus;
use App\Consent\RecipientType;
use App\Consent\Reconciliation\InvalidReconciliationRow;
use App\Consent\Reconciliation\IysConsentChange;
use App\Consent\Reconciliation\IysReconciliationParser;
use App\Consent\Reconciliation\ReconciliationError;
use App\Consent\Reconciliation\ReconciliationFile;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * TEST ONLY. A made-up semicolon format to exercise the generic
 * reconciliation pipeline; it is NOT the İYS change report format (which
 * is unknown) and is never registered outside tests.
 *
 *   address;channel;recipient_type;status;occurred_at;source
 */
final class TestChangeReportParser implements IysReconciliationParser
{
    private const HEADER = 'address;channel;recipient_type;status;occurred_at;source';

    public function parse(ReconciliationFile $file): iterable
    {
        foreach ($file->lines() as $n => $line) {
            if ($line instanceof InvalidReconciliationRow) {
                yield $n => $line;

                continue;
            }
            if ($n === 1) {
                if ($line !== self::HEADER) {
                    throw new ReconciliationError('HEADER_MISMATCH');
                }

                continue;
            }
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, ';', '"', '');
            try {
                yield $n => new IysConsentChange($n, (string) $cells[0], ConsentChannel::from(strtoupper((string) $cells[1])), RecipientType::from(strtoupper((string) $cells[2])),
                    ['ONAY' => ConsentStatus::Granted, 'RET' => ConsentStatus::Revoked][strtoupper((string) $cells[3])] ?? throw new \ValueError('status'),
                    CarbonImmutable::parse((string) $cells[4]), isset($cells[5]) ? ReconciliationFile::cell((string) $cells[5]) : null);
            } catch (Throwable) {
                yield $n => new InvalidReconciliationRow($n, 'ROW_FORMAT');
            }
        }
    }
}
