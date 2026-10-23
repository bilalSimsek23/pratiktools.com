<?php

namespace App\Commerce\Data;

/**
 * @template T
 */
final readonly class RecordPage
{
    /**
     * @param  list<T>  $records
     */
    public function __construct(
        public array $records,
        public ?string $nextPageToken,
        public int $apiCalls = 1,
    ) {}
}
