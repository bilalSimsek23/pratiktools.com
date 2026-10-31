<?php

namespace App\Site;

use App\Audit\Actor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;

/**
 * Brand images of the public site (panel: Site Ayarları › Marka). Uploads go
 * through the hardened SiteMedia pipeline (JPEG/PNG/WebP only, re-encoded,
 * no SVG); icons are square and stored as PNG. A replaced image gets a new
 * media id, so a new URL: browsers and CDNs never keep showing the old one.
 *
 * Logos: "light" is for a light background, "dark" for a dark one. The
 * header and footer background is a brand setting; without a logo for it
 * the site shows the text brand.
 */
final class SiteBrand
{
    /** @var array<string, array{label: string, kind: string, min: int, hint: string}> */
    public const IMAGES = [
        'header_logo_light' => ['label' => 'Header logosu — açık zemin', 'kind' => 'image', 'min' => 32, 'hint' => 'Yatay logo, şeffaf PNG veya WebP önerilir; en az 32 px yükseklik (önerilen ≥ 96 px).'],
        'header_logo_dark' => ['label' => 'Header logosu — koyu zemin', 'kind' => 'image', 'min' => 32, 'hint' => 'Header zemini “koyu” seçildiğinde kullanılır.'],
        'footer_logo_light' => ['label' => 'Footer logosu — açık zemin', 'kind' => 'image', 'min' => 32, 'hint' => 'Boşsa header logosu kullanılır.'],
        'footer_logo_dark' => ['label' => 'Footer logosu — koyu zemin', 'kind' => 'image', 'min' => 32, 'hint' => 'Footer zemini “koyu” seçildiğinde kullanılır; boşsa koyu zemin header logosu.'],
        'favicon' => ['label' => 'Favicon', 'kind' => 'icon', 'min' => 48, 'hint' => 'Kare PNG/JPEG/WebP, en az 48×48 (önerilen 512×512). 32, 48 ve 192 px PNG olarak üretilir.'],
        'apple_touch_icon' => ['label' => 'Apple Touch Icon', 'kind' => 'icon', 'min' => 180, 'hint' => 'Kare, en az 180×180, şeffaflıksız önerilir. 180 px PNG olarak üretilir.'],
        'og_image' => ['label' => 'Varsayılan sosyal paylaşım (Open Graph) görseli', 'kind' => 'image', 'min' => 200, 'hint' => 'Sayfaya özel görsel yoksa kullanılır. Önerilen 1200×630.'],
    ];

    public function __construct(private readonly SiteSettings $settings, private readonly SiteMedia $media) {}

    /**
     * Uploads a new image for a slot (or removes it with null), then drops
     * the previous one if nothing else uses it (no orphans).
     */
    public function setImage(string $slot, ?UploadedFile $file, string $alt, Actor $actor): void
    {
        $spec = self::IMAGES[$slot] ?? throw new SiteError('Geçersiz marka görseli.');
        $previous = (string) $this->settings->get('brand')[$slot];
        // Stored (row and files) before the setting points at it.
        $id = $file === null ? null : $this->media->upload($file, SiteContents::line($alt, 200) ?: $this->settings->get('brand')['site_name'].' '.$spec['label'], $actor, $spec['kind'], $spec['min']);
        $this->settings->setBrandImage($slot, $id, $actor);
        if ($previous !== '' && $previous !== $id) {
            $this->media->delete($previous, $actor);
        }
    }

    /**
     * What the public layout needs, cached per site version.
     *
     * @return array{name: string, short: string, header: array{background: string, logo: ?array<string, mixed>}, footer: array{background: string, logo: ?array<string, mixed>}, favicon: ?array<string, mixed>, apple_touch_icon: ?array<string, mixed>, og_image: ?array<string, mixed>, schema_logo: ?array<string, mixed>}
     */
    public function view(): array
    {
        return Cache::remember('site:brand:'.SiteContents::version(), 600, function () {
            $b = $this->settings->get('brand');
            $media = fn (string $slot, string $kind) => ($m = SiteMedia::describe($b[$slot] ?: null)) !== null && $m['kind'] === $kind ? $m : null;
            $header = $b['header_background'];
            $footer = $b['footer_background'];

            return [
                'name' => (string) $b['site_name'], 'short' => (string) ($b['short_name'] ?: $b['site_name']),
                'header' => ['background' => $header, 'logo' => $media("header_logo_{$header}", 'image')],
                'footer' => ['background' => $footer, 'logo' => $media("footer_logo_{$footer}", 'image') ?? $media("header_logo_{$footer}", 'image')],
                'favicon' => $media('favicon', 'icon'), 'apple_touch_icon' => $media('apple_touch_icon', 'icon'), 'og_image' => $media('og_image', 'image'),
                // Organization logo in search results: a logo made for a light background.
                'schema_logo' => $media('header_logo_light', 'image') ?? $media('footer_logo_light', 'image'),
            ];
        });
    }

    /**
     * The smallest WebP variant of a logo (keeps transparency), as a path.
     *
     * @param  array<string, mixed>  $logo  SiteMedia::describe()
     */
    public static function logoSrc(array $logo): string
    {
        $webp = (array) $logo['webp'];
        ksort($webp, SORT_NUMERIC);

        return (string) (reset($webp) ?: end($logo['jpg']));
    }
}
