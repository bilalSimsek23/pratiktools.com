<?php

namespace App\Connectors\Ticimax;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Helpers for Ticimax SOAP payloads decoded to arrays.
 */
final class TicimaxResponse
{
    /**
     * A repeated element arrives as a list, a single element as an object,
     * and none as null or an empty array. Always returns a list.
     *
     * @return list<array<string, mixed>>
     */
    public static function list(mixed $node, string $child): array
    {
        if (! is_array($node) || $node === []) {
            return [];
        }

        $items = array_key_exists($child, $node) ? $node[$child] : $node;

        if (! is_array($items) || $items === []) {
            return [];
        }

        return array_is_list($items) ? array_values(array_filter($items, 'is_array')) : [$items];
    }

    /**
     * @return list<scalar>
     */
    public static function scalars(mixed $node, string $child): array
    {
        if (! is_array($node) || ! array_key_exists($child, $node)) {
            return [];
        }

        $value = $node[$child];

        return is_array($value) ? array_values(array_filter($value, 'is_scalar')) : (is_scalar($value) ? [$value] : []);
    }

    /**
     * Ticimax returns local times without an offset and uses 2000-01-01 /
     * 0001-01-01 as "never". Returns UTC, or null for sentinels.
     */
    public static function date(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '' || str_starts_with($value, '0001-01-01') || str_starts_with($value, '2000-01-01T00:00:00')) {
            return null;
        }

        try {
            $hasOffset = preg_match('/(Z|[+\-]\d{2}:\d{2})$/', $value) === 1;

            return ($hasOffset ? CarbonImmutable::parse($value) : CarbonImmutable::parse($value, $timezone))->utc();
        } catch (Throwable) {
            return null;
        }
    }

    public static function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public static function id(mixed $value): ?string
    {
        return is_numeric($value) && (int) $value > 0 ? (string) (int) $value : null;
    }

    public static function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    public static function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Ticimax date filters are local time without offset.
     */
    public static function filterDate(CarbonImmutable $date, string $timezone): string
    {
        return $date->setTimezone($timezone)->format('Y-m-d\TH:i:s');
    }
}
