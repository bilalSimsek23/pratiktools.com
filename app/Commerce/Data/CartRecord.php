<?php

namespace App\Commerce\Data;

use Carbon\CarbonImmutable;

final readonly class CartRecord
{
    /**
     * @param  list<CartItemRecord>  $items
     */
    public function __construct(
        public string $externalId,
        public ?string $memberExternalId,
        public ?string $email,
        public CarbonImmutable $createdAt,
        public ?string $currency,
        public array $items,
    ) {}
}
