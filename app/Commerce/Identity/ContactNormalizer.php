<?php

namespace App\Commerce\Identity;

/**
 * Deterministic normalization of contact identifiers.
 */
final class ContactNormalizer
{
    /**
     * @param  list<string>  $marketplaceDomains
     */
    public function __construct(private readonly array $marketplaceDomains = []) {}

    public function email(?string $value): ?string
    {
        $email = strtolower(trim((string) $value));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * True for relay/masked addresses that marketplaces hand out; such an
     * address does not identify a person and is never used as an identity.
     */
    public function isMarketplaceEmail(?string $email): bool
    {
        $domain = strtolower((string) substr((string) strrchr((string) $email, '@'), 1));

        foreach ($this->marketplaceDomains as $marketplace) {
            if ($domain === $marketplace || str_ends_with($domain, '.'.$marketplace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * E.164 where it can be derived deterministically. Turkish numbers in
     * the usual local forms (05xx..., 5xx..., 905xx..., +905xx...) become
     * +905xxxxxxxxx; other numbers need an explicit "+" country code.
     */
    public function phone(?string $value, string $defaultCountry = 'TR'): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $hasPlus = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($raw, '00')) {
            $digits = substr($digits, 2);
        }

        if ($defaultCountry === 'TR' && ! $hasPlus) {
            $digits = match (true) {
                strlen($digits) === 11 && str_starts_with($digits, '0') => '90'.substr($digits, 1),
                strlen($digits) === 10 => '90'.$digits,
                default => $digits,
            };
            $hasPlus = strlen($digits) === 12 && str_starts_with($digits, '90');
        }

        if (! $hasPlus || strlen($digits) < 8 || strlen($digits) > 15 || str_starts_with($digits, '0')) {
            return null;
        }

        if (str_starts_with($digits, '90') && strlen($digits) !== 12) {
            return null;
        }

        return '+'.$digits;
    }
}
