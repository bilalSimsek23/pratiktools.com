<?php

namespace App\Consent\Reconciliation;

use RuntimeException;

final class ReconciliationError extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message === '' ? $reason : $message);
    }
}
