<?php

namespace App\Email\Templates;

use InvalidArgumentException;

/**
 * UNKNOWN_VARIABLE, MISSING_VARIABLE, INVALID_SYNTAX, INVALID_URL_VARIABLE,
 * UNSUBSCRIBE_MISSING.
 */
final class TemplateError extends InvalidArgumentException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
