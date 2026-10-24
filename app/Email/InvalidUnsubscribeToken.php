<?php

namespace App\Email;

use RuntimeException;

final class InvalidUnsubscribeToken extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Invalid unsubscribe token ({$reason}).");
    }
}
