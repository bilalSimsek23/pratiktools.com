<?php

namespace App\Consent\Via;

use App\Consent\ConsentChannel;
use App\Consent\RecipientType;
use Carbon\CarbonImmutable;

/**
 * A verified ViA outcome. kind "consent" completes a consent acquisition
 * (via_consent_requests.reference), kind "ret" a ViA Ret started after an
 * unsubscribe (consent_write_intents.reference).
 */
final readonly class ViaConsentEvent
{
    public const CONSENT = 'consent';

    public const RET = 'ret';

    public function __construct(
        public string $kind,
        public bool $success,
        public string $frameId,
        public string $reference,
        public string $transactionId,
        public CarbonImmutable $occurredAt,
        public ConsentChannel $channel = ConsentChannel::Email,
        public RecipientType $recipientType = RecipientType::Individual,
        public ?string $failureCode = null,
    ) {}
}
