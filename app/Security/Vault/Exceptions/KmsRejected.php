<?php

namespace App\Security\Vault\Exceptions;

use Throwable;

/**
 * The key service refused the request (access denied, disabled or missing
 * key, wrong region, invalid parameters). Retrying will not help; an
 * operator must fix the configuration.
 */
final class KmsRejected extends VaultException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function isRetryable(): bool
    {
        return false;
    }
}
