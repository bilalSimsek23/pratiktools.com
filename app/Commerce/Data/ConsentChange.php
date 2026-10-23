<?php

namespace App\Commerce\Data;

use Carbon\CarbonImmutable;

final readonly class ConsentChange
{
    public const EMAIL = 'EMAIL';

    public const SMS = 'SMS';

    public function __construct(
        public string $externalId,
        public ?string $memberExternalId,
        public string $channel,
        public bool $granted,
        public ?string $email,
        public ?string $phone,
        public ?string $source,
        public CarbonImmutable $occurredAt,
    ) {}
}
