<?php

namespace App\Commerce\Data;

final readonly class CategoryRecord
{
    public function __construct(
        public string $externalId,
        public ?string $parentExternalId,
        public string $name,
        public ?string $code,
        public ?string $url,
        public bool $isActive,
        public int $sort,
    ) {}
}
