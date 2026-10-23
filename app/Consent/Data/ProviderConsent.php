<?php

namespace App\Consent\Data;

use App\Consent\ConsentChannel;
use App\Consent\ConsentStatus;
use App\Consent\RecipientType;
use Carbon\CarbonImmutable;

/**
 * One consent state or movement as a provider reports it, already mapped to
 * canonical terms. The provider's own labels stay in $source.
 */
final readonly class ProviderConsent
{
    public function __construct(
        public string $recipient,
        public ConsentChannel $channel,
        public RecipientType $recipientType,
        public ConsentStatus $status,
        public ?string $source = null,
        public ?CarbonImmutable $consentDate = null,
        public ?CarbonImmutable $creationDate = null,
        public ?string $transactionId = null,
    ) {}
}
