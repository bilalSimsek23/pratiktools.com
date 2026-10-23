<?php

namespace App\Commerce\Data;

use Carbon\CarbonImmutable;

final readonly class OrderRecord
{
    public const CHANNEL_WEB = 'web';

    public const CHANNEL_MOBILE_APP = 'mobile_app';

    public const CHANNEL_MARKETPLACE = 'marketplace';

    public const CHANNEL_OTHER = 'other';

    /**
     * @param  list<OrderItemRecord>  $items
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $externalId,
        public ?string $number,
        public ?string $memberExternalId,
        public ?string $email,
        public string $channel,
        public ?string $source,
        public bool $isMarketplace,
        public ?int $statusCode,
        public ?string $statusLabel,
        public bool $countsAsRevenue,
        public CarbonImmutable $placedAt,
        public ?CarbonImmutable $updatedAt,
        public ?string $currency,
        public float $netTotal,
        public float $grandTotal,
        public float $discountTotal,
        public float $shippingTotal,
        public ?string $couponCode,
        public float $couponAmount,
        public array $items,
        public array $attributes = [],
    ) {}
}
