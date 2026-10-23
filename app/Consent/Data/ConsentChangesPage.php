<?php

namespace App\Consent\Data;

final readonly class ConsentChangesPage
{
    /**
     * @param  list<ProviderConsent>  $changes
     */
    public function __construct(
        public array $changes,
        public ?string $after,
        public int $apiCalls = 1,
    ) {}
}
