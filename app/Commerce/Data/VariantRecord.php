<?php

namespace App\Commerce\Data;

use Carbon\CarbonImmutable;

final readonly class VariantRecord
{
    /**
     * @param  list<array{type: string, name: string, value: string}>  $options
     */
    public function __construct(
        public string $externalId,
        public ?string $sku,
        public ?string $barcode,
        public ?float $price,
        public ?float $salePrice,
        public ?string $currency,
        public float $stock,
        public bool $isActive,
        public ?string $color,
        public ?string $size,
        public ?string $imageUrl,
        public array $options,
        public ?CarbonImmutable $stockUpdatedAt,
    ) {}
}
