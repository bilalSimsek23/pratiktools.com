<?php

namespace App\Security\Vault;

use App\Security\Vault\Contracts\KeyEncryptionService;
use App\Security\Vault\Exceptions\VaultException;

/**
 * Wraps with the current driver; unwraps with whichever configured driver
 * knows the data key's kek_id ("cs-…" cloud secret keyring, "arn:aws:kms:…"
 * AWS KMS, "local-…" development only). This is how a rotation or a move
 * between providers (cloud secret → KMS) happens without downtime: the old
 * provider stays configured until vault:rewrap --all has moved every key.
 */
final class CompositeKeyEncryptionService implements KeyEncryptionService
{
    /**
     * @param  list<KeyEncryptionService>  $others  unwrap-only providers
     */
    public function __construct(
        private readonly KeyEncryptionService $current,
        private readonly array $others = [],
    ) {}

    public function currentKeyId(): string
    {
        return $this->current->currentKeyId();
    }

    public function knows(string $kekId): bool
    {
        return $this->resolve($kekId) !== null;
    }

    public function wrap(#[\SensitiveParameter] string $dataKey, string $context): string
    {
        return $this->current->wrap($dataKey, $context);
    }

    public function unwrap(string $wrappedKey, string $keyId, string $context): string
    {
        $provider = $this->resolve($keyId) ?? throw new VaultException("KEK [{$keyId}] is not available.");

        return $provider->unwrap($wrappedKey, $keyId, $context);
    }

    private function resolve(string $kekId): ?KeyEncryptionService
    {
        foreach ([$this->current, ...$this->others] as $provider) {
            if ($provider->knows($kekId)) {
                return $provider;
            }
        }

        return null;
    }
}
