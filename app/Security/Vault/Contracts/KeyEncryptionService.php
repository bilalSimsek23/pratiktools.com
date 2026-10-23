<?php

namespace App\Security\Vault\Contracts;

/**
 * Wraps and unwraps tenant data encryption keys with a key encryption key.
 * Implementations: CloudSecretKeyEncryptionService (production default),
 * AwsKmsKeyEncryptionService (optional), LocalKeyEncryptionService
 * (development and tests), CompositeKeyEncryptionService (routing by key
 * id).
 */
interface KeyEncryptionService
{
    /**
     * Identifier of the KEK used for new wraps.
     */
    public function currentKeyId(): string;

    /**
     * Whether a data key wrapped under this KEK id can be unwrapped here.
     */
    public function knows(string $kekId): bool;

    /**
     * @param  string  $context  Bound to the wrap as authenticated data.
     * @return string Opaque wrapped key, safe to store.
     */
    public function wrap(#[\SensitiveParameter] string $dataKey, string $context): string;

    public function unwrap(string $wrappedKey, string $keyId, string $context): string;
}
