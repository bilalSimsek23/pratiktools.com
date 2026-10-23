<?php

namespace App\Security\Vault\Exceptions;

final class DecryptionFailed extends VaultException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Secret could not be decrypted (wrong key, tampered data or mismatched binding).', 0, $previous);
    }
}
