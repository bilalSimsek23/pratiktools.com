<?php

namespace App\Security\Vault;

use App\Security\Vault\Contracts\KeyEncryptionService;
use App\Security\Vault\Exceptions\VaultException;
use Aws\Kms\KmsClient;

/**
 * The only place a KEK driver name becomes an implementation.
 *
 * The configured driver wraps; every other configured provider stays
 * available for unwrapping by key id, so rotations and provider moves never
 * strand a data key. The local driver is never loaded in production.
 */
final class KeyEncryptionServiceFactory
{
    /** Every driver that has an implementation. */
    public const IMPLEMENTED = ['local', 'cloud_secret', 'aws_kms'];

    /** Drivers acceptable in production. */
    public const PRODUCTION_DRIVERS = ['cloud_secret', 'aws_kms'];

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
     * The AWS SDK is an optional dependency (composer suggest); it is only
     * needed when the aws_kms driver is used.
     */
    public static function awsSdkInstalled(): bool
    {
        return class_exists(KmsClient::class);
    }

    /**
     * @param  array<string, mixed>  $vault  config('platform.vault')
     */
    public static function make(array $vault, bool $production): KeyEncryptionService
    {
        $driver = (string) ($vault['kek_driver'] ?? '');

        if ($production && ! in_array($driver, self::PRODUCTION_DRIVERS, true)) {
            throw new VaultException("KEK driver [{$driver}] is not allowed in production.");
        }

        $current = self::driver($driver, $vault);
        $others = [];

        if ($driver !== 'cloud_secret' && ! empty($vault['cloud_secret']['key'])) {
            $others[] = self::driver('cloud_secret', $vault);
        }
        if ($driver !== 'aws_kms' && ! empty($vault['aws_kms']['key_id']) && self::awsSdkInstalled()) {
            $others[] = self::driver('aws_kms', $vault);
        }
        if (! $production && $driver !== 'local' && ! empty($vault['local']['key'])) {
            $others[] = self::driver('local', $vault);
        }

        return new CompositeKeyEncryptionService($current, $others);
    }

    /**
     * @param  array<string, mixed>  $vault
     */
    private static function driver(string $driver, array $vault): KeyEncryptionService
    {
        return match ($driver) {
            'cloud_secret' => CloudSecretKeyEncryptionService::fromConfig($vault['cloud_secret'] ?? []),
            'local' => LocalKeyEncryptionService::fromConfig($vault['local'] ?? []),
            'aws_kms' => self::awsSdkInstalled()
                ? AwsKmsKeyEncryptionService::fromConfig($vault['aws_kms'] ?? [])
                : throw new VaultException('The aws_kms driver needs aws/aws-sdk-php (composer require aws/aws-sdk-php).'),
            default => throw new VaultException("KEK driver [{$driver}] is not implemented."),
        };
    }
}
