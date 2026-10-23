<?php

namespace App\Integrations\Errors;

use RuntimeException;
use Throwable;

/**
 * Normalized provider failure. The message is safe to store and show: it
 * never contains credentials, request or response bodies.
 */
final class ProviderError extends RuntimeException
{
    public const AUTH = 'auth';

    public const RATE_LIMITED = 'rate_limited';

    public const RETRYABLE = 'retryable';

    public const PERMANENT = 'permanent';

    public const SCHEMA_MISMATCH = 'schema_mismatch';

    public function __construct(
        public readonly string $category,
        string $message,
        public readonly ?string $operation = null,
        public readonly int $retryAfterMs = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct(mb_substr($message, 0, 400), 0, $previous);
    }

    public function isRetryable(): bool
    {
        return in_array($this->category, [self::RETRYABLE, self::RATE_LIMITED], true);
    }

    public static function auth(string $message, ?string $operation = null): self
    {
        return new self(self::AUTH, $message, $operation);
    }

    public static function schema(string $message, ?string $operation = null): self
    {
        return new self(self::SCHEMA_MISMATCH, $message, $operation);
    }
}
