<?php

namespace App\Consent\Via;

/**
 * Public frame configuration of a tenant (no secret).
 */
final readonly class ViaFrameConfig
{
    public function __construct(public string $integrationId, public string $frameId, public string $environment) {}
}
