<?php

namespace App\Consent\Via;

use App\Security\SensitiveValue;

/**
 * The code the person typed, for the OTP ViA sent. The code is a
 * SensitiveValue: never logged or serialized.
 */
final readonly class ViaOtpVerifyRequest
{
    public function __construct(public string $reference, public string $providerReference, public SensitiveValue $code) {}
}
