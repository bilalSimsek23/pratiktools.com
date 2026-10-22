<?php

namespace App\RateLimiting;

final readonly class RateLimitDecision
{
    public function __construct(
        public bool $allowed,
        public int $retryAfterMs = 0,
        public ?string $limitedBy = null,
    ) {}
}
