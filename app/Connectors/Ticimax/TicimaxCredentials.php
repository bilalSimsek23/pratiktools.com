<?php

namespace App\Connectors\Ticimax;

use App\Security\SensitiveValue;

/**
 * Connection settings of one tenant's Ticimax store. The member code (WS
 * authorization code) is held as a SensitiveValue and revealed only when a
 * request is built.
 */
final readonly class TicimaxCredentials
{
    public function __construct(
        public SensitiveValue $memberCode,
        public string $serviceBaseUrl,
        public string $storefrontUrl,
        public string $timezone,
    ) {}
}
