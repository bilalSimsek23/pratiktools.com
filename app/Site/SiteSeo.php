<?php

namespace App\Site;

use Illuminate\Support\Carbon;

/**
 * Meta tags and schema.org JSON-LD for public pages. Structured data only
 * describes what the page visibly shows (FAQPage only with a visible FAQ)
 * and what Site Ayarları records (no invented address, phone or profile).
 *
 * Fallback order for every value: the page's SEO field → the page's own
 * content → the global settings (SEO / Marka).
 */
final class SiteSeo
{
    public function __construct(private readonly SiteSettings $settings, private readonly SiteBrand $brand) {}

    public static function absolute(string $path): string
    {
        return app(SiteSettings::class)->canonicalBase().($path === '' ? '/' : $path);
    }

    /**
     * Meta for a CMS item (service, solution, project, article, page).
     *
     * @param  array<string, mixed>  $item  SiteQuery::find()
     * @return array<string, mixed>
     */
    public function forContent(array $item, string $description, string $ogType = 'website'): array
    {
        return $this->meta(($item['seo_title'] ?? null) ?: (string) $item['title'], ($item['seo_description'] ?? null) ?: $description, (string) $item['url'],
            index: ! ($item['robots_noindex'] ?? false), image: $item['image'] ?? null, ogType: $ogType,
            og: ['title' => $item['og_title'] ?? null, 'description' => $item['og_description'] ?? null]);
    }

    /**
     * @param  string  $title  empty: the default SEO title, else the site name
     * @param  string  $description  empty: the default meta description
     * @param  array<string, mixed>|null  $image  SiteMedia::describe(); null: the default share image
     * @param  array{title?: ?string, description?: ?string}  $og  Open Graph overrides
     * @return array<string, mixed>
     */
    public function meta(string $title, string $description, string $path, bool $index = true, ?array $image = null, string $ogType = 'website', array $og = []): array
    {
        $seo = $this->settings->get('seo');
        $brand = $this->brand->view();
        $site = $brand['name'];
        $title = SiteContents::line($title, 200) ?: (string) $seo['default_title'] ?: $site;
        $suffix = (string) $seo['title_suffix'] ?: "| {$site}";
        $full = str_contains($title, $site) || str_contains($title, $suffix) ? $title : $title.(preg_match('/^[\p{L}\p{N}]/u', $suffix) === 1 ? ' | ' : ' ').$suffix;
        $description = SiteContents::line($description, 400) ?: (string) $seo['default_description'];
        $image ??= $brand['og_image'];
        $imageUrl = $image === null || ($image['jpg'] ?? []) === [] ? null : self::absolute((string) end($image['jpg']));
        $index = $index && $seo['robots_index'];
        $ogTitle = SiteContents::line((string) ($og['title'] ?? ''), 200) ?: $title;
        $ogDescription = SiteContents::line((string) ($og['description'] ?? ''), 400) ?: SiteContents::line($description, 300);

        return [
            'title' => $full,
            // Written page descriptions as entered; derived ones (excerpt/body) were cut by the caller.
            'description' => SiteContents::line($description, 400),
            'canonical' => self::absolute($path),
            'robots' => ($index ? 'index' : 'noindex').', '.($seo['robots_follow'] ? 'follow' : 'nofollow').($index ? ', max-image-preview:large' : ''),
            'og' => array_filter(['og:type' => $ogType, 'og:title' => $ogTitle, 'og:description' => $ogDescription, 'og:url' => self::absolute($path),
                'og:site_name' => $site, 'og:locale' => 'tr_TR', 'og:image' => $imageUrl, 'og:image:alt' => $imageUrl === null ? null : ($image['alt'] ?? null)]),
            'twitter' => array_filter(['twitter:card' => $imageUrl === null ? 'summary' : 'summary_large_image', 'twitter:title' => $ogTitle,
                'twitter:description' => $ogDescription, 'twitter:image' => $imageUrl, 'twitter:image:alt' => $imageUrl === null ? null : ($image['alt'] ?? null)]),
        ];
    }

    /**
     * Only what Site Ayarları records: no invented address or profile.
     *
     * @return array<string, mixed>
     */
    public function organization(): array
    {
        $o = $this->settings->get('organization');
        $seo = $this->settings->get('seo');
        $logo = $this->brand->view()['schema_logo'];

        return array_filter(['@type' => 'Organization', '@id' => self::absolute('/').'#organization', 'name' => $seo['organization_name'] ?: $o['name'], 'url' => self::absolute('/'),
            'description' => $seo['organization_description'] ?: null,
            'logo' => $logo === null ? null : ['@type' => 'ImageObject', 'url' => self::absolute((string) end($logo['jpg']))],
            'email' => $o['email'] ?: null, 'telephone' => $o['phone'] ?: null, 'sameAs' => $this->settings->sameAs() ?: null]);
    }

    /**
     * @return array<string, mixed>
     */
    public function website(): array
    {
        $brand = $this->brand->view();

        return array_filter(['@type' => 'WebSite', '@id' => self::absolute('/').'#website', 'name' => $brand['name'],
            'alternateName' => $brand['short'] !== $brand['name'] ? $brand['short'] : null, 'url' => self::absolute('/'), 'inLanguage' => 'tr-TR',
            'publisher' => ['@id' => self::absolute('/').'#organization']]);
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
