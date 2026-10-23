<?php

namespace App\Commerce\Data;

final readonly class OrderItemRecord
{
    public function __construct(
        public string $externalId,
        public ?string $productExternalId,
        public ?string $variantExternalId,
        public ?string $sku,
        public ?string $barcode,
        public ?string $name,
        public float $quantity,
        public ?float $unitListPrice,
        public ?float $unitPrice,
        public float $taxAmount,
        public float $discountAmount,
        public float $rowTotal,
        public ?int $statusCode,
        public ?string $statusLabel,
    ) {}
}
