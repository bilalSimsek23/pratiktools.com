<?php

namespace App\Consent\Via;

final readonly class ViaOtpStartResult
{
    public function __construct(public bool $accepted, public ?string $providerReference = null, public ?string $failureCode = null) {}
}
