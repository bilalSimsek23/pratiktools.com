<?php

namespace App\Commerce\Data;

use Carbon\CarbonImmutable;

final readonly class ProductRecord
{
    /**
     * @param  list<string>  $categoryExternalIds
     * @param  list<VariantRecord>  $variants
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $externalId,
        public string $name,
        public ?string $brand,
        public ?string $brandExternalId,
        public ?string $mainCategoryExternalId,
        public array $categoryExternalIds,
        public ?string $url,
        public ?string $imageUrl,
        public bool $isActive,
        public float $totalStock,
        public ?CarbonImmutable $createdAt,
        public array $variants,
        public array $attributes = [],
        // False when the source returned the card without its variants
        // (Ticimax stock-date queries do); variants must then be left alone.
        public bool $variantsIncluded = true,
    ) {}
}
