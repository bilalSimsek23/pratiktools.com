<?php

namespace App\Email;

use InvalidArgumentException;

/**
 * A message that must never be sent as built: bad address, header
 * injection attempt, oversized body. Carries a reason code only.
 */
final class InvalidEmailMessage extends InvalidArgumentException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
