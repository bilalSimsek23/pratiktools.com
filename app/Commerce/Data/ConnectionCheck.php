<?php

namespace App\Commerce\Data;

final readonly class ConnectionCheck
{
    /**
     * @param  array<string, bool>  $checks  check name => passed
     */
    public function __construct(
        public bool $ok,
        public array $checks,
        public ?string $errorCategory = null,
        public ?string $message = null,
    ) {}
}
