<?php

namespace App\Email\Data;

final readonly class ProviderSendResult
{
    public function __construct(
        public string $providerMessageId,
        public int $providerRequests = 1,
    ) {}
}
