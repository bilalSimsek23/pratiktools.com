<?php

namespace App\Consent\Via;

final readonly class ViaStatus
{
    public const NOT_CONFIGURED = 'not_configured';

    // A frame is stored, but the official event format is not available,
    // so no event can be verified yet.
    public const AWAITING_OFFICIAL_SPEC = 'awaiting_official_spec';

    public const CONFIGURED = 'configured';

    public function __construct(public string $state, public string $detail) {}

    public function configured(): bool
    {
        return $this->state === self::CONFIGURED;
    }
}
