<?php

namespace App\Leads;

use InvalidArgumentException;

final class LeadError extends InvalidArgumentException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
