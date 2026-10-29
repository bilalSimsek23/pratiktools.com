<?php

namespace App\Site;

/**
 * Lead attribution from first and last touch, as sent by the page (JSON)
 * or derived from the request. Only safe, coarse values are kept:
 * utm_* (restricted characters), the landing path (no query string), the
 * referrer host, and whether an ad click id was present (never its value).
 *
 * Source classes: google_ads, meta_ads, ai_search, campaign, organic,
 * referral, direct.
 */
final class Attribution
{
    public const CLASSES = ['google_ads', 'meta_ads', 'ai_search', 'campaign', 'organic', 'referral', 'direct'];

    private const UTM = ['source', 'medium', 'campaign', 'content', 'term'];

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function touch(array $raw): array
    {
        $touch = [];
        foreach (self::UTM as $k) {
            $v = $raw[$k] ?? $raw['utm_'.$k] ?? null;
            if (is_string($v) && preg_match('/^[A-Za-z0-9_.\- +|:]{1,100}$/', $v) === 1) {
                $touch[$k] = strtolower(trim($v));
            }
        }
        $landing = is_string($raw['landing'] ?? null) ? strtolower((string) parse_url((string) $raw['landing'], PHP_URL_PATH)) : '';
        if (preg_match('#^/[a-z0-9/_-]{0,200}$#', $landing) === 1) {
            $touch['landing'] = $landing;
        }
        $host = is_string($raw['referrer'] ?? null) ? strtolower((string) (parse_url((string) $raw['referrer'], PHP_URL_HOST) ?? $raw['referrer'])) : '';
        if (preg_match('/^[a-z0-9.-]{3,253}$/', $host) === 1 && $host !== self::ownHost()) {
            $touch['referrer_host'] = $host;
        }
        foreach (['gclid', 'fbclid', 'msclkid'] as $id) {
            if (! empty($raw[$id])) {
                $touch['has_'.$id] = true;
            }
        }
        if (is_string($raw['at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}/', $raw['at']) === 1) {
            $touch['at'] = substr($raw['at'], 0, 10);
        }
        $touch['class'] = self::classify($touch);

        return $touch;
    }

    /**
     * @param  array<string, mixed>  $t
     */
    public static function classify(array $t): string
    {
        $source = (string) ($t['source'] ?? '');
        $medium = (string) ($t['medium'] ?? '');
        $paid = in_array($medium, ['cpc', 'ppc', 'paid', 'paid_social', 'paidsocial', 'display'], true);
        $host = (string) ($t['referrer_host'] ?? '');
        $matches = fn (array $list) => collect($list)->contains(fn ($d) => $host === $d || str_ends_with($host, '.'.ltrim($d, '.')) || (str_ends_with($d, '.') && str_contains($host, $d)));

        return match (true) {
            ! empty($t['has_gclid']) || ($paid && in_array($source, ['google', 'googleads', 'adwords'], true)) => 'google_ads',
            $paid && in_array($source, ['facebook', 'instagram', 'meta', 'fb', 'ig'], true) => 'meta_ads',
            in_array($source, (array) config('site.ai_referrers'), true) || in_array($source, ['chatgpt', 'openai', 'perplexity', 'gemini', 'copilot'], true) || $matches((array) config('site.ai_referrers')) => 'ai_search',
            $source !== '' || isset($t['campaign']) => 'campaign',
            $matches((array) config('site.search_referrers')) => 'organic',
            $host !== '' => 'referral',
            default => 'direct',
        };
    }

    private static function ownHost(): string
    {
        return strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }
}
