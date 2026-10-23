<?php

namespace App\Connectors\Iys;

use App\Security\SensitiveValue;

final readonly class IysCredentials
{
    public function __construct(
        public string $integrationId,
        public SensitiveValue $username,
        public SensitiveValue $password,
        public int $iysCode,
        public int $brandCode,
        public string $baseUrl,
        public string $environment,
    ) {}

    /**
     * Cache namespace for tokens: changes when the credentials change, so
     * a rotated password never reuses an old token.
     */
    public function tokenKey(): string
    {
        return 'iys:token:'.$this->integrationId.':'.substr(hash('sha256', $this->username->reveal()."\0".$this->password->reveal()), 0, 16);
    }
}
