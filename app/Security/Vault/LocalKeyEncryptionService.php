<?php

namespace App\Security\Vault;

use App\Security\Vault\Contracts\KeyEncryptionService;
use App\Security\Vault\Exceptions\VaultException;

/**
 * Development and test KEK provider (SECRET_VAULT_LOCAL_KEK, never
 * APP_KEY). Same primitive as cloud_secret; ids carry the "local-" prefix
 * and are refused in production.
 *
 * Ids are derived from the key unless SECRET_VAULT_LOCAL_KEK_ID names one
 * (kept for data keys created before derived ids existed); explicit ids
 * must start with "local-".
 */
final class LocalKeyEncryptionService implements KeyEncryptionService
{
    public const PREFIX = 'local-';

    /** @var array<string, string> legacy explicit id => derived id */
    private array $aliases = [];

    private function __construct(private readonly SymmetricKeyring $keyring) {}

    /**
     * @param  array{key_id?: ?string, key?: ?string, previous?: ?string}  $config
     */
    public static function fromConfig(array $config): self
    {
        if (empty($config['key'])) {
            throw new VaultException('The local KEK is not configured. Run `php artisan vault:generate-local-keys`.');
        }

        $current = SymmetricKeyring::decode((string) $config['key'], 'SECRET_VAULT_LOCAL_KEK');
        $previous = [];
        $aliases = [];
        if (! empty($config['key_id'])) {
            $aliases[self::checkId((string) $config['key_id'])] = $current;
        }

        foreach (array_filter(array_map(trim(...), explode(',', (string) ($config['previous'] ?? '')))) as $entry) {
            [$id, $encoded] = str_contains($entry, ':') ? explode(':', $entry, 2) : [null, $entry];
            $raw = SymmetricKeyring::decode($encoded, 'SECRET_VAULT_LOCAL_PREVIOUS_KEKS entry');
            $previous[] = $raw;
            if ($id !== null && $id !== '') {
                $aliases[self::checkId($id)] = $raw;
            }
        }

        $service = new self(new SymmetricKeyring(self::PREFIX, $current, $previous));
        foreach ($aliases as $alias => $raw) {
            $service->aliases[$alias] = SymmetricKeyring::deriveId(self::PREFIX, $raw);
        }

        return $service;
    }

    /**
     * New data keys record the explicit id when one is configured, so
     * existing development data stays readable.
     */
    public function currentKeyId(): string
    {
        return array_search($this->keyring->currentKeyId(), $this->aliases, true) ?: $this->keyring->currentKeyId();
    }

    public function knows(string $kekId): bool
    {
        return $this->keyring->knows($this->aliases[$kekId] ?? $kekId);
    }

    public function wrap(#[\SensitiveParameter] string $dataKey, string $context): string
    {
        return $this->keyring->wrap($dataKey, $context);
    }

    public function unwrap(string $wrappedKey, string $keyId, string $context): string
    {
        return $this->keyring->unwrap($wrappedKey, $this->aliases[$keyId] ?? $keyId, $context);
    }

    private static function checkId(string $id): string
    {
        if (! str_starts_with($id, self::PREFIX)) {
            throw new VaultException('Local KEK ids must start with "local-".');
        }

        return $id;
    }
}
