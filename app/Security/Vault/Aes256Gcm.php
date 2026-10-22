<?php

namespace App\Security\Vault;

use App\Security\Vault\Exceptions\DecryptionFailed;
use App\Security\Vault\Exceptions\VaultException;

/**
 * AES-256-GCM with a random 96-bit nonce and 128-bit tag. Associated data
 * binds a ciphertext to where it is stored, so it cannot be moved to another
 * tenant or record and still decrypt.
 */
final class Aes256Gcm
{
    private const CIPHER = 'aes-256-gcm';

    private const KEY_BYTES = 32;

    private const NONCE_BYTES = 12;

    /**
     * @return array{nonce: string, ciphertext: string, tag: string}
     */
    public static function encrypt(#[\SensitiveParameter] string $key, #[\SensitiveParameter] string $plaintext, string $aad): array
    {
        self::assertKey($key);

        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);

        if ($ciphertext === false) {
            throw new VaultException('Encryption failed.');
        }

        return ['nonce' => $nonce, 'ciphertext' => $ciphertext, 'tag' => $tag];
    }

    public static function decrypt(#[\SensitiveParameter] string $key, string $nonce, string $ciphertext, string $tag, string $aad): string
    {
        self::assertKey($key);

        if (strlen($nonce) !== self::NONCE_BYTES || strlen($tag) !== 16) {
            throw new DecryptionFailed;
        }

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);

        if ($plaintext === false) {
            throw new DecryptionFailed;
        }

        return $plaintext;
    }

    public static function generateKey(): string
    {
        return random_bytes(self::KEY_BYTES);
    }

    private static function assertKey(string $key): void
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new VaultException('AES-256-GCM requires a 32-byte key.');
        }
    }
}
