<?php

namespace App\Commerce\Contracts;

use App\Commerce\Data\CustomerRecord;
use App\Commerce\Data\OrderRecord;
use App\Commerce\Data\ProductRecord;

/**
 * Optional: fetch single records by source id and source-side counts.
 * Used to reconcile stored data against the source field by field.
 */
interface LooksUpRecords
{
    public function member(string $externalId): ?CustomerRecord;

    public function product(string $externalId): ?ProductRecord;

    public function order(string $externalId): ?OrderRecord;

    public function productCount(): ?int;
}
