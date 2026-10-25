<?php

namespace App\Approvals;

use RuntimeException;

final class ApprovalError extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
