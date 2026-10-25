<?php

namespace App\Segments;

use InvalidArgumentException;

/**
 * A segment definition outside the whitelist: TOO_DEEP, TOO_MANY_RULES,
 * UNKNOWN_FIELD, UNKNOWN_OPERATOR, INVALID_VALUE, INVALID_NODE.
 */
final class SegmentRuleError extends InvalidArgumentException
{
    public function __construct(public readonly string $reason, public readonly string $path, string $message)
    {
        parent::__construct("{$path}: {$message}");
    }
}
