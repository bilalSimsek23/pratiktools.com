<?php

namespace App\Security\Vault;

use App\Security\Vault\Contracts\KeyEncryptionService;
use App\Security\Vault\Exceptions\DecryptionFailed;
use App\Security\Vault\Exceptions\VaultException;

/**
 * Development and test KEK provider. The KEK comes from the environment
 * (SECRET_VAULT_LOCAL_KEK), never from APP_KEY. Refused in production
 * unless explicitly allowed.
 */
final class LocalKeyEncryptionService implements KeyEncryptionService
{
    /**
     * @param  array<string, string>  $keys  key id => raw 32-byte key
     */
    public function __construct(
        private readonly string $currentKeyId,
        private readonly array $keys,
    ) {
        if (! isset($keys[$currentKeyId])) {
            throw new VaultException('The local KEK is not configured. Run `php artisan vault:generate-local-keys`.');
        }
    }

    /**
     * @param  array{key_id?: string, key?: ?string, previous?: ?string}  $config
     */
    public static function fromConfig(array $config): self
    {
        $keys = [];
        $currentId = (string) ($config['key_id'] ?? 'local-v1');

        if (! empty($config['key'])) {
            $keys[$currentId] = self::decodeKey((string) $config['key']);
        }

        foreach (array_filter(explode(',', (string) ($config['previous'] ?? ''))) as $pair) {
            [$id, $encoded] = array_pad(explode(':', trim($pair), 2), 2, '');
            if ($id !== '' && $encoded !== '') {
                $keys[$id] = self::decodeKey($encoded);
            }
        }

        return new self($currentId, $keys);
    }

    public function currentKeyId(): string
    {
        return $this->currentKeyId;
    }

    public function wrap(#[\SensitiveParameter] string $dataKey, string $context): string
    {
        $box = Aes256Gcm::encrypt($this->keys[$this->currentKeyId], $dataKey, $context);

        return base64_encode(json_encode([
            'n' => base64_encode($box['nonce']),
            'c' => base64_encode($box['ciphertext']),
            't' => base64_encode($box['tag']),
        ], JSON_THROW_ON_ERROR));
    }

    public function unwrap(string $wrappedKey, string $keyId, string $context): string
    {
        $kek = $this->keys[$keyId] ?? throw new VaultException("KEK [{$keyId}] is not available.");

        $box = json_decode((string) base64_decode($wrappedKey, true), true);
        if (! is_array($box) || ! isset($box['n'], $box['c'], $box['t'])) {
            throw new DecryptionFailed;
        }

        return Aes256Gcm::decrypt(
            $kek,
            (string) base64_decode($box['n'], true),
            (string) base64_decode($box['c'], true),
            (string) base64_decode($box['t'], true),
            $context,
        );
    }

    private static function decodeKey(string $encoded): string
    {
        $raw = base64_decode($encoded, true);

        if ($raw === false || strlen($raw) !== 32) {
            throw new VaultException('Local KEK must be 32 bytes, base64 encoded.');
        }

        return $raw;
    }
}
