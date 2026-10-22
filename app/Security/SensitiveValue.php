<?php

namespace App\Security;

use JsonSerializable;
use LogicException;
use Stringable;

/**
 * Wraps a decrypted secret so that it cannot leak through string casting,
 * JSON encoding, var_dump/dd, logs or queue serialization. The plaintext is
 * available only through an explicit reveal().
 */
final class SensitiveValue implements JsonSerializable, Stringable
{
    public const REDACTED = '[redacted]';

    public function __construct(#[\SensitiveParameter] private readonly string $value) {}

    public function reveal(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return self::REDACTED;
    }

    public function jsonSerialize(): string
    {
        return self::REDACTED;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => self::REDACTED];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Sensitive values cannot be serialized.');
    }
}
