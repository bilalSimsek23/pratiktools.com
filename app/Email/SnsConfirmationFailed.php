<?php

namespace App\Email;

use RuntimeException;

/**
 * An SNS subscription could not be confirmed. The message is a reason code
 * only (never the SubscribeURL or a request error).
 */
final class SnsConfirmationFailed extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
