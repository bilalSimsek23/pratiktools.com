<?php

namespace App\Site;

use Illuminate\Support\Carbon;

/**
 * Meta tags and schema.org JSON-LD for public pages. Structured data only
 * describes what the page visibly shows (FAQPage only with a visible FAQ).
 */
final class SiteSeo
{
    public function __construct(private readonly SiteSettings $settings) {}

    public static function absolute(string $path): string
    {
        return rtrim((string) config('app.url'), '/').($path === '' ? '/' : $path);
    }

    /**
     * @param  array<string, mixed>|null  $image  SiteMedia::describe()
     * @return array<string, mixed>
     */
    public function meta(string $title, string $description, string $path, bool $index = true, ?array $image = null, string $ogType = 'website'): array
    {
        $site = (string) config('site.name');
        $full = str_contains($title, $site) ? $title : "{$title} | {$site}";
        $imageUrl = $image === null ? null : self::absolute((string) (end($image['jpg']) ?: ''));

        return [
            'title' => $full,
            'description' => SiteContents::line($description, 170),
            'canonical' => self::absolute($path),
            'robots' => $index ? 'index, follow, max-image-preview:large' : 'noindex, follow',
            'og' => array_filter(['og:type' => $ogType, 'og:title' => $title, 'og:description' => SiteContents::line($description, 200), 'og:url' => self::absolute($path),
                'og:site_name' => $site, 'og:locale' => 'tr_TR', 'og:image' => $imageUrl, 'og:image:alt' => $image['alt'] ?? null]),
            'twitter' => array_filter(['twitter:card' => $imageUrl === null ? 'summary' : 'summary_large_image', 'twitter:title' => $title,
                'twitter:description' => SiteContents::line($description, 200), 'twitter:image' => $imageUrl]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function organization(): array
    {
        $o = $this->settings->get('organization');

        return array_filter(['@type' => 'Organization', '@id' => self::absolute('/').'#organization', 'name' => $o['name'], 'url' => self::absolute('/'),
            'email' => $o['email'] ?: null, 'telephone' => $o['phone'] ?: null, 'sameAs' => $o['same_as'] ?: null]);
    }

    /**
     * @return array<string, mixed>
     */
    public function website(): array
    {
        return ['@type' => 'WebSite', '@id' => self::absolute('/').'#website', 'name' => (string) config('site.name'), 'url' => self::absolute('/'), 'inLanguage' => 'tr-TR',
            'publisher' => ['@id' => self::absolute('/').'#organization']];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function service(array $item, string $description): array
    {
        return array_filter(['@type' => 'Service', 'name' => $item['title'], 'description' => $description, 'url' => self::absolute($item['url']),
            'serviceType' => $item['category']['name'] ?? null, 'areaServed' => ['@type' => 'Country', 'name' => 'Türkiye'],
            'provider' => ['@id' => self::absolute('/').'#organization']]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function article(array $item, string $description): array
    {
        $author = trim((string) ($item['data']['author'] ?? ''));

        return array_filter(['@type' => 'BlogPosting', 'headline' => $item['title'], 'description' => $description, 'url' => self::absolute($item['url']),
            'mainEntityOfPage' => self::absolute($item['url']), 'datePublished' => self::date($item['published_at']), 'dateModified' => self::date($item['updated_at']),
            'author' => $author === '' ? ['@id' => self::absolute('/').'#organization'] : ['@type' => 'Person', 'name' => $author],
            'publisher' => ['@id' => self::absolute('/').'#organization'], 'inLanguage' => 'tr-TR',
            'image' => isset($item['image']['jpg']) ? self::absolute((string) (end($item['image']['jpg']) ?: '')) : null]);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $crumbs  [name, path]
     * @return array<string, mixed>
     */
    public function breadcrumbs(array $crumbs): array
    {
        return ['@type' => 'BreadcrumbList', 'itemListElement' => array_map(fn ($c, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => self::absolute($c[1])],
            $crumbs, array_keys($crumbs))];
    }

    /**
     * Only for a FAQ that is shown on the page.
     *
     * @param  array<int, mixed>  $faq  stored data, untrusted shape
     * @return array<string, mixed>|null
     */
    public function faq(array $faq): ?array
    {
        $faq = array_values(array_filter($faq, fn ($f) => is_array($f) && trim((string) ($f['q'] ?? '')) !== '' && trim((string) ($f['a'] ?? '')) !== ''));

        return $faq === [] ? null : ['@type' => 'FAQPage', 'mainEntity' => array_map(fn ($f) => ['@type' => 'Question', 'name' => $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]], $faq)];
    }

    /**
     * One JSON-LD document; "<" etc. escaped so it can never close the script tag.
     *
     * @param  list<array<string, mixed>|null>  $nodes
     */
    public static function jsonLd(array $nodes): string
    {
        return (string) json_encode(['@context' => 'https://schema.org', '@graph' => array_values(array_filter($nodes))],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }

    private static function date(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value)->toIso8601String();
    }
}
