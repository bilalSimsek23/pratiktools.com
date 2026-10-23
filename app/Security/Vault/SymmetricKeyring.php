<?php

namespace App\Security\Vault;

use App\Security\Vault\Exceptions\DecryptionFailed;
use App\Security\Vault\Exceptions\VaultException;

/**
 * The shared primitive behind the local and cloud_secret KEK drivers: a
 * set of 256-bit keys, one current, wrapping data keys with AES-256-GCM
 * and the wrap context as additional authenticated data.
 *
 * Key ids are derived from the key material (HMAC-SHA256 over a fixed
 * label), so a label can never point at the wrong key and a wrong key is
 * detected by its id alone. The id reveals nothing about the key.
 */
final class SymmetricKeyring
{
    public const ID_LABEL = 'pratiktools.kek-id.v1';

    /** @var array<string, string> key id => raw 32-byte key */
    private array $keys = [];

    private string $currentId;

    /**
     * @param  list<string>  $previous  raw keys still accepted for unwrapping
     */
    public function __construct(
        private readonly string $prefix,
        #[\SensitiveParameter] string $current,
        #[\SensitiveParameter] array $previous = [],
    ) {
        $this->currentId = $this->add($current);
        foreach ($previous as $key) {
            $id = self::deriveId($prefix, $key);
            if (isset($this->keys[$id])) {
                throw new VaultException('A previous KEK duplicates the current KEK or another previous KEK.');
            }
            $this->add($key);
        }
    }

    public static function deriveId(string $prefix, #[\SensitiveParameter] string $raw): string
    {
        return $prefix.substr(hash_hmac('sha256', self::ID_LABEL, $raw), 0, 16);
    }

    /**
     * Strict base64 of exactly 32 bytes.
     */
    public static function decode(#[\SensitiveParameter] string $encoded, string $name): string
    {
        $raw = base64_decode(trim($encoded), true);

        if ($raw === false || strlen($raw) !== 32) {
            throw new VaultException("{$name} must be base64 of exactly 32 bytes.");
        }

        return $raw;
    }

    public function currentKeyId(): string
    {
        return $this->currentId;
    }

    public function knows(string $kekId): bool
    {
        return isset($this->keys[$kekId]);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->keys);
    }

    public function wrap(#[\SensitiveParameter] string $dataKey, string $context): string
    {
        $box = Aes256Gcm::encrypt($this->keys[$this->currentId], $dataKey, $context);

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

    private function add(#[\SensitiveParameter] string $raw): string
    {
        if (strlen($raw) !== 32) {
            throw new VaultException('A KEK must be exactly 32 bytes.');
        }
        $id = self::deriveId($this->prefix, $raw);
        $this->keys[$id] = $raw;

        return $id;
    }

    /**
     * Keys are never part of a dump.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['prefix' => $this->prefix, 'current' => $this->currentId, 'ids' => array_keys($this->keys)];
    }

    /**
     * @return array<string, never>
     */
    public function __serialize(): array
    {
        throw new VaultException('A keyring cannot be serialized.');
    }
}
