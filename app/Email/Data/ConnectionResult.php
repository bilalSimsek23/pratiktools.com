<?php

namespace App\Email\Data;

/**
 * Outcome of a read-only provider check. Details are provider-neutral
 * account facts (sandbox, quota, rate, enforcement), never credentials.
 */
final readonly class ConnectionResult
{
    /**
     * @param  array<string, mixed>  $account
     */
    public function __construct(
        public bool $ok,
        public array $account = [],
        public ?string $errorCategory = null,
        public ?string $errorMessage = null,
    ) {}
}
