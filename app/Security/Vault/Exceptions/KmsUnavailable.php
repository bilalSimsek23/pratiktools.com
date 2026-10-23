<?php

namespace App\Security\Vault\Exceptions;

use Throwable;

/**
 * The key service could not answer (throttling, timeout, network, internal
 * error). Safe to retry later. Messages never contain key material or
 * credentials.
 */
final class KmsUnavailable extends VaultException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
