<?php

namespace App\Security\Vault\Contracts;

/**
 * Wraps and unwraps tenant data encryption keys with a key encryption key
 * held by a KMS. Implementations: LocalKeyEncryptionService (development,
 * tests); AWS KMS / GCP KMS / Vault Transit adapters are added before
 * production.
 */
interface KeyEncryptionService
{
    /**
     * Identifier of the KEK used for new wraps.
     */
    public function currentKeyId(): string;

    /**
     * @param  string  $context  Bound to the wrap as authenticated data.
     * @return string Opaque wrapped key, safe to store.
     */
    public function wrap(#[\SensitiveParameter] string $dataKey, string $context): string;

    public function unwrap(string $wrappedKey, string $keyId, string $context): string;
}
