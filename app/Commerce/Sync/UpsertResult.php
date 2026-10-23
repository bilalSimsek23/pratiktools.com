<?php

namespace App\Commerce\Sync;

final class UpsertResult
{
    public int $inserted = 0;

    public int $updated = 0;

    public int $skipped = 0;

    /** @var array<string, true> */
    public array $touchedCustomers = [];

    public function touch(?string $customerId): void
    {
        if ($customerId !== null) {
            $this->touchedCustomers[$customerId] = true;
        }
    }
}
