<?php

namespace App\Connectors\Ses;

use App\Security\SensitiveValue;
use InvalidArgumentException;

/**
 * Credentials of one tenant's SES integration. Both key parts come from the
 * vault; region and configuration set are settings (not secret).
 */
final readonly class SesCredentials
{
    public function __construct(
        public string $integrationId,
        public string $region,
        public SensitiveValue $accessKeyId,
        public SensitiveValue $secretAccessKey,
        public ?string $configurationSet = null,
    ) {
        if (! self::validRegion($region)) {
            throw new InvalidArgumentException('Not an AWS region name.');
        }
        if ($configurationSet !== null && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $configurationSet) !== 1) {
            throw new InvalidArgumentException('Configuration set names contain only letters, digits, "_" and "-".');
        }
    }

    public static function validRegion(string $region): bool
    {
        return preg_match('/^[a-z]{2}(-gov)?-[a-z]+-\d{1,2}$/', $region) === 1;
    }

    /**
     * The vault secret is JSON {"access_key_id": …, "secret_access_key": …}.
     */
    public static function fromSecret(string $integrationId, string $region, #[\SensitiveParameter] string $secret, ?string $configurationSet): self
    {
        $data = json_decode($secret, true);
        if (! is_array($data) || ! is_string($data['access_key_id'] ?? null) || ! is_string($data['secret_access_key'] ?? null)
            || preg_match('/^[A-Z0-9]{16,128}$/', $data['access_key_id']) !== 1 || strlen($data['secret_access_key']) < 16) {
            throw new InvalidArgumentException('The stored SES secret is not an access key pair.');
        }

        return new self($integrationId, $region, new SensitiveValue($data['access_key_id']), new SensitiveValue($data['secret_access_key']), $configurationSet);
    }
}
