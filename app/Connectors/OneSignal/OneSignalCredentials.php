<?php

namespace App\Connectors\OneSignal;

use App\Security\SensitiveValue;
use InvalidArgumentException;

final readonly class OneSignalCredentials
{
    public function __construct(
        public string $integrationId,
        public string $appId,
        public SensitiveValue $apiKey,
    ) {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $appId)) {
            throw new InvalidArgumentException('OneSignal App ID must be a UUID.');
        }
    }
}
