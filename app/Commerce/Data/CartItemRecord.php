<?php

namespace App\Commerce\Data;

final readonly class CartItemRecord
{
    public function __construct(
        public string $externalId,
        public ?string $productExternalId,
        public ?string $variantExternalId,
        public ?string $sku,
        public ?string $name,
        public float $quantity,
        public ?float $unitPrice,
        public ?float $unitPriceWithTax,
        public ?string $imageUrl,
    ) {}
}
