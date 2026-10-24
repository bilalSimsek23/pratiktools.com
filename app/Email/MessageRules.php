<?php

namespace App\Email;

/**
 * Address, header and text rules applied before a message is stored and
 * again before it is handed to a provider. Anything that could inject a
 * header (CR, LF, other control characters) is refused, never cleaned.
 */
final class MessageRules
{
    /**
     * Lower-cased address if it is a plain, 7-bit ASCII e-mail address.
     */
    public static function address(?string $value, string $reason): string
    {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > 320 || preg_match('/[^\x21-\x7e]/', $value) === 1
            || filter_var($value, FILTER_VALIDATE_EMAIL) === false || str_contains($value, '<') || str_contains($value, ',')) {
            throw new InvalidEmailMessage($reason, 'Not a valid single e-mail address.');
        }

        return strtolower($value);
    }

    /**
     * One-line text (subject, display name): no control characters.
     */
    public static function line(?string $value, int $maxLength, string $reason, bool $required = true): ?string
    {
        if ($value === null || trim($value) === '') {
            if ($required) {
                throw new InvalidEmailMessage($reason, 'A value is required.');
            }

            return null;
        }
        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $value) === 1 || mb_strlen($value) > $maxLength) {
            throw new InvalidEmailMessage($reason, 'Text contains control characters or is too long.');
        }

        return trim($value);
    }

    /**
     * Only allowlisted custom headers, printable ASCII values, the
     * provider's length limits (name ≤ 126, value ≤ 995, both ≤ 996).
     *
     * @param  array<array-key, mixed>  $headers  as stored (JSON), checked here
     * @return array<string, string>
     */
    public static function headers(array $headers): array
    {
        $allowed = [];
        foreach ((array) config('email.allowed_headers') as $name) {
            $allowed[strtolower((string) $name)] = (string) $name;
        }

        $clean = [];
        foreach ($headers as $name => $value) {
            $canonical = $allowed[strtolower((string) $name)] ?? null;
            if ($canonical === null) {
                throw new InvalidEmailMessage('HEADER_NOT_ALLOWED', 'Custom header is not on the allowlist.');
            }
            if (! is_string($value) || $value === '' || preg_match('/^[\x20-\x7e]+$/', $value) !== 1 || strlen($value) > 995 || strlen($canonical) + strlen($value) > 996) {
                throw new InvalidEmailMessage('INVALID_HEADER', 'Header value must be printable ASCII on one line.');
            }
            $clean[$canonical] = $value;
        }

        return $clean;
    }

    /**
     * Message tags: provider-neutral, but kept within the strictest common
     * rules (ASCII letters, digits, "_", "-", ".", up to 256 characters).
     *
     * @param  array<array-key, mixed>  $tags  as stored (JSON), checked here
     * @return array<string, string>
     */
    public static function tags(array $tags): array
    {
        $clean = [];
        foreach ($tags as $name => $value) {
            if (preg_match('/^[A-Za-z0-9_\-]{1,256}$/', (string) $name) !== 1 || ! is_string($value) || preg_match('/^[A-Za-z0-9_\-.@]{1,256}$/', $value) !== 1) {
                throw new InvalidEmailMessage('INVALID_TAG', 'Tags may contain only letters, digits, "_", "-" and ".".');
            }
            $clean[(string) $name] = $value;
        }

        return $clean;
    }
}
