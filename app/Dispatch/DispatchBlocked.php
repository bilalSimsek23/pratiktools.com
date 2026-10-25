<?php

namespace App\Dispatch;

use RuntimeException;

final class DispatchBlocked extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
