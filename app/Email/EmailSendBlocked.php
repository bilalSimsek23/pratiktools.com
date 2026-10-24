<?php

namespace App\Email;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * An e-mail send was refused before any network request. With a retry
 * time the message is deferred (quota, rate), otherwise blocked.
 */
final class EmailSendBlocked extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly ?CarbonImmutable $retryAt = null)
    {
        parent::__construct($message);
    }
}
