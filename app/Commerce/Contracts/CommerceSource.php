<?php

namespace App\Commerce\Contracts;

use App\Commerce\Data\CartRecord;
use App\Commerce\Data\CategoryRecord;
use App\Commerce\Data\ConnectionCheck;
use App\Commerce\Data\CustomerRecord;
use App\Commerce\Data\OrderRecord;
use App\Commerce\Data\ProductRecord;
use App\Commerce\Data\RecordPage;
use App\Commerce\Data\SyncWindow;

/**
 * Read-only access to a commerce platform (Ticimax first; Shopify, ikas,
 * IdeaSoft later). Returns provider-independent records one page at a time;
 * it never writes to the platform and never touches our database.
 *
 * Page tokens are opaque to callers: a provider may encode several internal
 * query phases (for example three date filters for one customer delta) in
 * one token. A null next page means the window is complete.
 */
interface CommerceSource
{
    public function testConnection(): ConnectionCheck;

    /**
     * @return RecordPage<CustomerRecord>
     */
    public function customers(SyncWindow $window, ?string $pageToken = null): RecordPage;

    /**
     * @return RecordPage<CategoryRecord>
     */
    public function categories(?string $pageToken = null): RecordPage;

    /**
     * @return RecordPage<ProductRecord>
     */
    public function products(SyncWindow $window, ?string $pageToken = null): RecordPage;

    /**
     * @return RecordPage<OrderRecord>
     */
    public function orders(SyncWindow $window, ?string $pageToken = null): RecordPage;

    /**
     * @return RecordPage<CartRecord>
     */
    public function carts(SyncWindow $window, ?string $pageToken = null): RecordPage;
}
