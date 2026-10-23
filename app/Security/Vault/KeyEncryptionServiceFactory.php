<?php

namespace App\Security\Vault;

use App\Security\Vault\Contracts\KeyEncryptionService;
use App\Security\Vault\Exceptions\VaultException;

/**
 * The only place a KEK driver name becomes an implementation. Production
 * may only use drivers listed in PRODUCTION_DRIVERS.
 */
final class KeyEncryptionServiceFactory
{
    /** Every driver that has an implementation. */
    public const IMPLEMENTED = ['local', 'aws_kms'];

    /** Drivers acceptable in production (a real KMS). */
    public const PRODUCTION_DRIVERS = ['aws_kms'];

    /**
     * @return list<string>
     */
    public static function implemented(): array
    {
        return self::IMPLEMENTED;
    }

    /**
     * @return list<string>
     */
    public static function productionDrivers(): array
    {
        return self::PRODUCTION_DRIVERS;
    }

    /**
     * @param  array<string, mixed>  $vault  config('platform.vault')
     */
    public static function make(array $vault): KeyEncryptionService
    {
        return match ($driver = (string) ($vault['kek_driver'] ?? '')) {
            'local' => LocalKeyEncryptionService::fromConfig($vault['local'] ?? []),
            'aws_kms' => AwsKmsKeyEncryptionService::fromConfig($vault['aws_kms'] ?? []),
            default => throw new VaultException("KEK driver [{$driver}] is not implemented."),
        };
    }
}
