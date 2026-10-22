<?php

namespace App\Logging;

use App\Security\SensitiveValue;
use JsonSerializable;
use Stringable;

/**
 * Removes secrets and masks personal data before anything is logged or
 * stored as audit metadata.
 *
 * - Values under sensitive keys (secret, password, token, api key,
 *   authorization, cookie, encrypted_*, ...) are replaced entirely.
 * - E-mail addresses and Turkish mobile numbers inside any string are masked.
 * - SensitiveValue instances never reveal their content.
 */
final class Redactor
{
    public const REDACTED = '[redacted]';

    private const SENSITIVE_KEY = '/(secret|passw|token|api[_-]?key|authorization|cookie|signature|private[_-]?key|encrypted|credential|otp|two_factor)/i';

    private const EMAIL = '/\b([A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]*@([A-Za-z0-9])[A-Za-z0-9.\-]*\.([A-Za-z]{2,})\b/';

    private const TR_MOBILE = '/(?<!\d)(?:\+?90[\s\-]?|0)?5\d{2}[\s\-]?\d{3}[\s\-]?\d{2}[\s\-]?\d{2}(?!\d)/';

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redact(array $data, int $depth = 0): array
    {
        if ($depth > 8) {
            return ['_truncated' => true];
        }

        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            $clean[$key] = $this->value($value, $depth);
        }

        return $clean;
    }

    public function maskString(string $value): string
    {
        $value = (string) preg_replace(self::EMAIL, '$1***@$2***.$3', $value);

        return (string) preg_replace(self::TR_MOBILE, '[phone]', $value);
    }

    private function value(mixed $value, int $depth): mixed
    {
        return match (true) {
            $value instanceof SensitiveValue => self::REDACTED,
            is_string($value) => $this->maskString($value),
            is_array($value) => $this->redact($value, $depth + 1),
            $value instanceof JsonSerializable => $this->value($value->jsonSerialize(), $depth + 1),
            $value instanceof \Throwable => [
                'class' => $value::class,
                'message' => $this->maskString($value->getMessage()),
            ],
            $value instanceof Stringable => $this->maskString((string) $value),
            is_object($value) => ['class' => $value::class],
            default => $value,
        };
    }
}
