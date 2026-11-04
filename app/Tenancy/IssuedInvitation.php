<?php

namespace App\Tenancy;

use Carbon\CarbonInterface;

/**
 * A freshly issued invitation. The URL carries the single-use token and is
 * available only here, once: it is never stored, logged or audited.
 */
final readonly class IssuedInvitation
{
    public function __construct(
        public string $id,
        public string $url,
        public CarbonInterface $expiresAt,
    ) {}
}
