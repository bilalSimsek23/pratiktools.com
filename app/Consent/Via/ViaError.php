<?php

namespace App\Consent\Via;

use RuntimeException;

final class ViaError extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message === '' ? $reason : $message);
    }
}
