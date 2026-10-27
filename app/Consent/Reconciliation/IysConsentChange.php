<?php

namespace App\Consent\Reconciliation;

use App\Consent\ConsentChannel;
use App\Consent\ConsentStatus;
use App\Consent\RecipientType;
use Carbon\CarbonImmutable;

/**
 * One consent change read from an İYS change report: ONAY (Granted) or
 * RET (Revoked) of an address, as it happened at İYS. The address is only
 * held in memory while the row is classified; it is never stored.
 */
final readonly class IysConsentChange
{
    public function __construct(
        public int $rowNumber,
        public string $address,
        public ConsentChannel $channel,
        public RecipientType $recipientType,
        public ConsentStatus $status,
        public CarbonImmutable $occurredAt,
        public ?string $source = null,
        public ?string $transactionId = null,
    ) {
        if (! in_array($status, [ConsentStatus::Granted, ConsentStatus::Revoked], true)) {
            throw new \InvalidArgumentException('An İYS change is ONAY or RET.');
        }
    }
}
