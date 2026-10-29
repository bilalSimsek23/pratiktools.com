<?php

namespace App\Consent\Via;

use App\Consent\ConsentChannel;
use Carbon\CarbonImmutable;

/**
 * ViA's answer to an OTP verification, mapped by the transport. Only a
 * verified result with a provider reference can lead to ONAY. channel is
 * what ViA confirmed, when it says so; it must match the request.
 */
final readonly class ViaOtpVerifyResult
{
    public function __construct(
        public bool $verified,
        public ?string $providerReference,
        public ?CarbonImmutable $occurredAt = null,
        public ?ConsentChannel $channel = null,
        public ?string $failureCode = null,
    ) {}
}
