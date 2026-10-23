<?php

namespace App\Commerce\Sync;

enum SyncEntity: string
{
    case Categories = 'categories';
    case Products = 'products';
    case Customers = 'customers';
    case Orders = 'orders';
    case Carts = 'carts';

    /**
     * Order used for "all": categories before products, customers before
     * orders and carts, so references usually resolve on the first pass.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Categories, self::Products, self::Customers, self::Orders, self::Carts];
    }

    public function overlapMinutes(): int
    {
        return (int) config("commerce.sync.overlap_minutes.{$this->value}", 30);
    }
}
