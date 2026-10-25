<?php

namespace App\Automation;

use InvalidArgumentException;

final class AutomationDefinitionError extends InvalidArgumentException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
