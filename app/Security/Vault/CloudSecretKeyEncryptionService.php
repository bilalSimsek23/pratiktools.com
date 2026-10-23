<?php

namespace App\Security\Vault;

use App\Security\Vault\Contracts\KeyEncryptionService;
use App\Security\Vault\Exceptions\VaultException;

/**
 * Production KEK provider: the platform KEK comes from a Laravel Cloud
 * Secret (SECRET_VAULT_KEK), previous KEKs from SECRET_VAULT_KEK_PREVIOUS
 * during a rotation. Key ids are "cs-" + a fingerprint derived from the key.
 *
 * The KEK lives only in the process environment. It is never stored in the
 * database, cache or logs; a database dump alone cannot unwrap any DEK.
 */
final class CloudSecretKeyEncryptionService implements KeyEncryptionService
{
    public const PREFIX = 'cs-';

    public const MAX_PREVIOUS = 3;

    public function __construct(private readonly SymmetricKeyring $keyring) {}

    /**
     * @param  array{key?: ?string, previous?: ?string}  $config
     */
    public static function fromConfig(array $config): self
    {
        if (empty($config['key'])) {
            throw new VaultException('SECRET_VAULT_KEK is not set.');
        }

        return new self(new SymmetricKeyring(
            self::PREFIX,
            SymmetricKeyring::decode((string) $config['key'], 'SECRET_VAULT_KEK'),
            self::previous((string) ($config['previous'] ?? '')),
        ));
    }

    /**
     * SECRET_VAULT_KEK_PREVIOUS: comma-separated base64 keys, at most
     * MAX_PREVIOUS. Ids are derived, so no labels are needed.
     *
     * @return list<string>
     */
    public static function previous(#[\SensitiveParameter] string $value): array
    {
        $entries = array_values(array_filter(array_map(trim(...), explode(',', $value)), fn ($v) => $v !== ''));

        if (count($entries) > self::MAX_PREVIOUS) {
            throw new VaultException('SECRET_VAULT_KEK_PREVIOUS holds more than '.self::MAX_PREVIOUS.' keys.');
        }

        return array_map(fn (string $e) => SymmetricKeyring::decode($e, 'SECRET_VAULT_KEK_PREVIOUS entry'), $entries);
    }

    public function currentKeyId(): string
    {
        return $this->keyring->currentKeyId();
    }

    public function knows(string $kekId): bool
    {
        return $this->keyring->knows($kekId);
    }

    public function wrap(#[\SensitiveParameter] string $dataKey, string $context): string
    {
        return $this->keyring->wrap($dataKey, $context);
    }

    public function unwrap(string $wrappedKey, string $keyId, string $context): string
    {
        return $this->keyring->unwrap($wrappedKey, $keyId, $context);
    }
}
