<?php

namespace App\Consent\Reconciliation;

use Generator;

/**
 * An uploaded İYS change report, treated as untrusted input:
 *
 * - size limits (empty and oversized files are refused before reading);
 * - read line by line (never the whole file); over-long lines, NUL bytes
 *   and invalid UTF-8 become invalid rows instead of being interpreted;
 * - a UTF-8 byte order mark is dropped;
 * - cell() strips leading spreadsheet formula characters (CSV injection)
 *   from any text a parser keeps.
 *
 * The checksum identifies the file for duplicate-import protection. The
 * file itself is not retained after analysis.
 */
final class ReconciliationFile
{
    private ?string $sha256 = null;

    public function __construct(public readonly string $path, public readonly string $originalName) {}

    public static function open(string $path, string $originalName): self
    {
        $size = is_file($path) ? (int) filesize($path) : 0;
        if ($size === 0) {
            throw new ReconciliationError('FILE_EMPTY', 'The report file is empty.');
        }
        if ($size > (int) config('consent.reconciliation.max_file_bytes')) {
            throw new ReconciliationError('FILE_TOO_LARGE', 'The report file is larger than the allowed size.');
        }

        return new self($path, self::safeName($originalName));
    }

    public function size(): int
    {
        return (int) filesize($this->path);
    }

    public function sha256(): string
    {
        return $this->sha256 ??= (string) hash_file('sha256', $this->path);
    }

    /**
     * Lines without their line break, by 1-based line number; an unreadable
     * line is an InvalidReconciliationRow.
     *
     * @return Generator<int, string|InvalidReconciliationRow>
     */
    public function lines(): Generator
    {
        $max = (int) config('consent.reconciliation.max_line_bytes');
        $handle = fopen($this->path, 'rb') ?: throw new ReconciliationError('FILE_UNREADABLE');
        try {
            $number = 0;
            while (($line = fgets($handle, $max + 2)) !== false) {
                $number++;
                if (strlen($line) > $max && ! str_ends_with($line, "\n")) {
                    while (($rest = fgets($handle, $max + 2)) !== false && ! str_ends_with($rest, "\n"));
                    yield $number => new InvalidReconciliationRow($number, 'LINE_TOO_LONG');

                    continue;
                }
                $line = rtrim($line, "\r\n");
                if ($number === 1 && str_starts_with($line, "\xEF\xBB\xBF")) {
                    $line = substr($line, 3);
                }
                if (str_contains($line, "\0") || ! mb_check_encoding($line, 'UTF-8')) {
                    yield $number => new InvalidReconciliationRow($number, 'ENCODING');

                    continue;
                }
                yield $number => $line;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * A trimmed text cell without leading formula characters.
     */
    public static function cell(string $value): string
    {
        return ltrim(trim($value), "=+-@\t\r");
    }

    private static function safeName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $clean = preg_replace('/[^A-Za-z0-9._ -]/', '_', $base) ?? 'report';

        return mb_substr(ltrim($clean, '=+-@ .'), 0, 160) ?: 'report';
    }
}
