<?php

namespace App\Push;

use RuntimeException;

/**
 * A push send was refused before any network request.
 */
final class PushSendBlocked extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
