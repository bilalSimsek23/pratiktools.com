<?php

namespace App\Connectors\Ticimax;

use App\Integrations\Errors\ProviderError;
use JsonException;
use SoapFault;
use Throwable;
use TypeError;

/**
 * Maps SOAP faults and transport errors to normalized provider error
 * categories. Only the category and a short, generic reason are kept; the
 * original fault text is not stored because it may echo request data.
 */
final class TicimaxErrorClassifier
{
    private const AUTH = ['yetki kodu', 'yetkisiz', 'unauthorized', 'forbidden', 'http/1.1 401', 'http/1.1 403'];

    private const RATE = ['too many requests', 'http/1.1 429', 'rate limit'];

    private const RETRYABLE = [
        'could not connect', 'timed out', 'timeout', 'error fetching http headers', 'connection reset',
        'failed to load external entity', "couldn't load from", 'service unavailable', 'bad gateway',
        'gateway timeout', 'http/1.1 500', 'http/1.1 502', 'http/1.1 503', 'http/1.1 504', 'ssl', 'broken pipe',
    ];

    public static function classify(Throwable $e, string $operation): ProviderError
    {
        if ($e instanceof ProviderError) {
            return $e;
        }

        $text = strtolower($e->getMessage().' '.($e instanceof SoapFault ? (string) $e->faultcode : ''));

        $category = match (true) {
            self::contains($text, self::AUTH) => ProviderError::AUTH,
            self::contains($text, self::RATE) => ProviderError::RATE_LIMITED,
            self::contains($text, self::RETRYABLE) => ProviderError::RETRYABLE,
            $e instanceof JsonException, $e instanceof TypeError => ProviderError::SCHEMA_MISMATCH,
            str_contains($text, 'looks like we got no xml document'), str_contains($text, 'encoding') => ProviderError::SCHEMA_MISMATCH,
            default => ProviderError::PERMANENT,
        };

        return new ProviderError($category, self::reason($category, $e), $operation, previous: $e);
    }

    /**
     * Result-level errors (IsError / ErrorMessage in the response body).
     */
    public static function fromResultMessage(?string $message, string $operation): ProviderError
    {
        $text = strtolower((string) $message);

        return self::contains($text, self::AUTH)
            ? ProviderError::auth('Ticimax rejected the authorization code.', $operation)
            : new ProviderError(ProviderError::PERMANENT, 'Ticimax returned an error for this request.', $operation);
    }

    /**
     * @param  list<string>  $needles
     */
    private static function contains(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function reason(string $category, Throwable $e): string
    {
        return match ($category) {
            ProviderError::AUTH => 'Ticimax rejected the authorization code.',
            ProviderError::RATE_LIMITED => 'Ticimax rate limited the request.',
            ProviderError::RETRYABLE => 'Ticimax was unreachable or timed out ('.class_basename($e).').',
            ProviderError::SCHEMA_MISMATCH => 'Ticimax response did not match the expected structure.',
            default => 'Ticimax request failed ('.class_basename($e).').',
        };
    }
}
