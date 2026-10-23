<?php

namespace App\Security\Vault;

use App\Security\Vault\Exceptions\VaultException;

/**
 * Production rules for a cloud_secret platform KEK. Messages name the
 * setting, never a value.
 */
final class KekPolicy
{
    /**
     * SHA-256 of every key committed to this repository (phpunit.xml test
     * fixtures). A key known to the world is not a secret.
     * tests/Unit/KekPolicyTest keeps this list in sync with phpunit.xml.
     */
    public const KNOWN_TEST_KEY_HASHES = [
        'cba699f36981c1ceb10de7ca472ab11318ad189248a19196fe63efccdc392c1a', // APP_KEY
        '6a1a3803eb50ef34cc71822774d2427083e54d83c6bbc4479642ee4965e3672e', // SECRET_VAULT_LOCAL_KEK
        '0e362ef8b8822b22fef18f8fd7ef68bb3b8b1e5f0f30c304a332a3cca3dd2a6e', // SECRET_VAULT_FINGERPRINT_KEY
        '7b88c8f0f7341f41d1126f4abfa08c6f10f82190548799777a400b857d81f90f', // AUDIT_HASH_KEY
    ];

    /**
     * @param  array<string, mixed>  $cloudSecret  config('platform.vault.cloud_secret')
     * @param  array<string, ?string>  $otherSecrets  setting name => configured value (APP_KEY, AUDIT_HASH_KEY, ...)
     * @return list<string>
     */
    public static function problems(array $cloudSecret, array $otherSecrets): array
    {
        if (empty($cloudSecret['key'])) {
            return ['SECRET_VAULT_KEK is not set'];
        }

        try {
            $current = SymmetricKeyring::decode((string) $cloudSecret['key'], 'SECRET_VAULT_KEK');
        } catch (VaultException) {
            return ['SECRET_VAULT_KEK must be base64 of exactly 32 bytes'];
        }

        try {
            $previous = CloudSecretKeyEncryptionService::previous((string) ($cloudSecret['previous'] ?? ''));
        } catch (VaultException $e) {
            return ['SECRET_VAULT_KEK_PREVIOUS is malformed: '.$e->getMessage()];
        }

        $problems = [];
        foreach (['SECRET_VAULT_KEK' => [$current], 'SECRET_VAULT_KEK_PREVIOUS' => $previous] as $name => $keys) {
            foreach ($keys as $raw) {
                if (self::lowEntropy($raw)) {
                    $problems[] = "{$name} does not look random (generate it with php artisan vault:generate-kek)";
                }
                if (in_array(hash('sha256', $raw), self::KNOWN_TEST_KEY_HASHES, true)) {
                    $problems[] = "{$name} is a known test key from this repository";
                }
            }
        }

        $all = [$current, ...$previous];
        if (count(array_unique($all)) !== count($all)) {
            $problems[] = 'SECRET_VAULT_KEK must differ from every SECRET_VAULT_KEK_PREVIOUS entry';
        }

        foreach ($otherSecrets as $name => $value) {
            foreach (self::forms($value) as $form) {
                if (in_array($form, $all, true)) {
                    $problems[] = "SECRET_VAULT_KEK must not equal {$name}";
                    break;
                }
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * 32 random bytes have about 30 distinct values and are almost never
     * all printable; repeated patterns and typed passphrases are both.
     */
    public static function lowEntropy(#[\SensitiveParameter] string $raw): bool
    {
        return count(array_unique(str_split($raw))) < 16 || preg_match('/^[\x20-\x7e]+$/', $raw) === 1;
    }

    /**
     * The value as configured and, for base64 values, its raw bytes.
     *
     * @return list<string>
     */
    private static function forms(#[\SensitiveParameter] ?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $plain = str_starts_with($value, 'base64:') ? substr($value, 7) : $value;
        $decoded = base64_decode($plain, true);

        return array_values(array_filter([$value, $plain, $decoded === false ? null : $decoded], fn ($v) => $v !== null && $v !== ''));
    }
}
