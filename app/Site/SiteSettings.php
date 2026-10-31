<?php

namespace App\Site;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Tenancy\TenantDirectory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Website settings: the runtime source of truth for every changeable,
 * non-secret site setting (panel: Web Sitesi › Site Ayarları). Environment
 * values (SITE_OWNER_TENANT, SITE_MEDIA_DISK, SITE_CACHE_SECONDS) are only a
 * first-boot fallback while a setting has never been saved. Secrets never
 * live here (Laravel Cloud secrets / the vault).
 *
 * Analytics providers are configured by validated IDs only (never pasted
 * script): the site loads fixed, reviewed code after cookie consent.
 *
 * Groups:
 *  organization  name, email, phone, address, same_as (profile URLs), kvkk_url
 *  analytics     ga4_id (G-...), google_ads_id (AW-...), google_ads_lead_label, meta_pixel_id (digits)
 *  crawlers      gptbot: allow|disallow (model training; separate from search)
 *  owner         tenant: slug of the tenant that owns the website
 *  media         disk: a configured filesystem disk for uploaded images
 *  delivery      cache_seconds: browser/CDN cache of public pages
 *  brand         site_name, short_name, header/footer_background (light|dark),
 *                and media ids of the brand images (SiteBrand::IMAGES; set
 *                only through SiteBrand, uploads go through SiteMedia)
 *  seo           default_title, title_suffix, default_description,
 *                canonical_base (https in production), organization_name,
 *                organization_description, robots_index, robots_follow
 *  social        linkedin, instagram, youtube, facebook, x: profile URLs
 *                (https, on that network's own domain; empty = not shown)
 */
final class SiteSettings
{
    private const DEFAULTS = [
        'organization' => ['name' => 'PratikTools', 'email' => '', 'phone' => '', 'address' => '', 'same_as' => [], 'kvkk_url' => ''],
        'analytics' => ['ga4_id' => '', 'google_ads_id' => '', 'google_ads_lead_label' => '', 'meta_pixel_id' => ''],
        'crawlers' => ['gptbot' => 'disallow'],
        'owner' => ['tenant' => ''],
        'media' => ['disk' => ''],
        'delivery' => ['cache_seconds' => ''],
        'brand' => ['site_name' => 'PratikTools', 'short_name' => '', 'header_background' => 'light', 'footer_background' => 'light',
            'header_logo_light' => '', 'header_logo_dark' => '', 'footer_logo_light' => '', 'footer_logo_dark' => '',
            'favicon' => '', 'apple_touch_icon' => '', 'og_image' => ''],
        'seo' => ['default_title' => '', 'title_suffix' => '', 'default_description' => '', 'canonical_base' => '',
            'organization_name' => '', 'organization_description' => '', 'robots_index' => true, 'robots_follow' => true],
        'social' => ['linkedin' => '', 'instagram' => '', 'youtube' => '', 'facebook' => '', 'x' => ''],
    ];

    /** Accepted hosts per social profile (the domain itself or a subdomain of it). */
    public const SOCIAL = [
        'linkedin' => ['label' => 'LinkedIn', 'hosts' => ['linkedin.com']],
        'instagram' => ['label' => 'Instagram', 'hosts' => ['instagram.com']],
        'youtube' => ['label' => 'YouTube', 'hosts' => ['youtube.com']],
        'facebook' => ['label' => 'Facebook', 'hosts' => ['facebook.com']],
        'x' => ['label' => 'X / Twitter', 'hosts' => ['x.com', 'twitter.com']],
    ];

    public function __construct(private readonly AuditLogger $audit, private readonly TenantDirectory $tenants) {}

    /**
     * @return array{slug: ?string, source: string} source: settings | environment | none
     */
    public function ownerSlug(): array
    {
        $saved = (string) $this->get('owner')['tenant'];
        $env = config('site.owner_tenant');

        return match (true) {
            $saved !== '' => ['slug' => $saved, 'source' => 'settings'],
            is_string($env) && $env !== '' => ['slug' => $env, 'source' => 'environment'],
            default => ['slug' => null, 'source' => 'none'],
        };
    }

    /**
     * Saved choice, else SITE_MEDIA_DISK, else Laravel Cloud's attached
     * "site-media" disk when it is configured, else local.
     */
    public function mediaDisk(): string
    {
        $cloud = (string) config('site.cloud_media_disk');

        return (string) ($this->get('media')['disk'] ?: config('site.media_disk') ?: (config("filesystems.disks.{$cloud}") !== null ? $cloud : 'local'));
    }

    /**
     * Whether a disk keeps files across deployments (not the app's local disk).
     */
    public static function persistentDisk(string $disk): bool
    {
        return ! in_array(config("filesystems.disks.{$disk}.driver"), ['local', null], true);
    }

    public function cacheSeconds(): int
    {
        $saved = $this->get('delivery')['cache_seconds'];

        return is_numeric($saved) ? (int) $saved : (int) config('site.cache_seconds');
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $group): array
    {
        $stored = Cache::remember('site:settings:'.$group.':'.SiteContents::version(), 600,
            fn () => (array) json_decode((string) DB::table('site_settings')->where('key', $group)->value('value'), true));

        return array_replace(self::DEFAULTS[$group] ?? [], array_intersect_key($stored, self::DEFAULTS[$group] ?? []));
    }

    /**
     * Canonical origin of the public site (no trailing slash): the SEO
     * setting, else APP_URL.
     */
    public function canonicalBase(): string
    {
        return rtrim((string) ($this->get('seo')['canonical_base'] ?: config('app.url')), '/');
    }

    /**
     * Organization sameAs: the filled social profiles, then the extra
     * profile links of the organization group, without duplicates.
     *
     * @return list<string>
     */
    public function sameAs(): array
    {
        return array_values(array_unique([...array_values(array_filter($this->get('social'), fn ($u) => is_string($u) && $u !== '')),
            ...(array) $this->get('organization')['same_as']]));
    }

    /**
     * Points a brand image slot at a media id (or clears it). SiteBrand does
     * the upload and the cleanup of the previous file.
     */
    public function setBrandImage(string $slot, ?string $mediaId, Actor $actor): void
    {
        if (! array_key_exists($slot, SiteBrand::IMAGES)) {
            throw new SiteError('Geçersiz marka görseli.');
        }
        $this->write('brand', [...$this->get('brand'), $slot => $mediaId ?? ''], $actor,
            ['image' => $slot, 'removed' => $mediaId === null]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function set(string $group, array $input, Actor $actor): void
    {
        $clean = match ($group) {
            'organization' => [
                'name' => SiteContents::line((string) ($input['name'] ?? ''), 120) ?: 'PratikTools',
                'email' => filter_var(trim((string) ($input['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '',
                'phone' => preg_match('/^\+?[0-9 ()-]{7,24}$/', trim((string) ($input['phone'] ?? ''))) === 1 ? trim((string) $input['phone']) : '',
                'address' => SiteContents::text((string) ($input['address'] ?? ''), 300),
                'same_as' => array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($input['same_as'] ?? '')) ?: []), fn ($u) => preg_match('#^https://[^\s"<>]{3,200}$#', $u) === 1)),
                'kvkk_url' => preg_match('#^(https://[^\s"<>]{3,200}|/[a-z0-9/-]{1,100})$#', trim((string) ($input['kvkk_url'] ?? ''))) === 1 ? trim((string) $input['kvkk_url']) : '',
            ],
            'analytics' => [
                'ga4_id' => self::id((string) ($input['ga4_id'] ?? ''), '/^G-[A-Z0-9]{4,16}$/'),
                'google_ads_id' => self::id((string) ($input['google_ads_id'] ?? ''), '/^AW-[0-9]{6,14}$/'),
                'google_ads_lead_label' => self::id((string) ($input['google_ads_lead_label'] ?? ''), '/^[A-Za-z0-9_-]{4,64}$/'),
                'meta_pixel_id' => self::id((string) ($input['meta_pixel_id'] ?? ''), '/^[0-9]{8,20}$/'),
            ],
            'crawlers' => ['gptbot' => ($input['gptbot'] ?? '') === 'allow' ? 'allow' : 'disallow'],
            'owner' => ['tenant' => $this->owner((string) ($input['tenant'] ?? ''))],
            'media' => ['disk' => $this->disk((string) ($input['disk'] ?? ''))],
            'delivery' => ['cache_seconds' => is_numeric($input['cache_seconds'] ?? null) && (int) $input['cache_seconds'] >= 0 && (int) $input['cache_seconds'] <= 3600
                ? (string) (int) $input['cache_seconds'] : throw new SiteError('Önbellek süresi 0–3600 saniye olmalı.')],
            // Brand images are kept as they are (SiteBrand changes them).
            'brand' => [...$this->get('brand'),
                'site_name' => SiteContents::line((string) ($input['site_name'] ?? ''), 80) ?: throw new SiteError('Site adı gerekli.'),
                'short_name' => SiteContents::line((string) ($input['short_name'] ?? ''), 30),
                'header_background' => ($input['header_background'] ?? '') === 'dark' ? 'dark' : 'light',
                'footer_background' => ($input['footer_background'] ?? '') === 'dark' ? 'dark' : 'light',
            ],
            'seo' => [
                'default_title' => SiteContents::line((string) ($input['default_title'] ?? ''), 120),
                'title_suffix' => SiteContents::line((string) ($input['title_suffix'] ?? ''), 60),
                'default_description' => SiteContents::line((string) ($input['default_description'] ?? ''), 300),
                'canonical_base' => self::origin((string) ($input['canonical_base'] ?? '')),
                'organization_name' => SiteContents::line((string) ($input['organization_name'] ?? ''), 120),
                'organization_description' => SiteContents::line((string) ($input['organization_description'] ?? ''), 500),
                'robots_index' => filter_var($input['robots_index'] ?? false, FILTER_VALIDATE_BOOL),
                'robots_follow' => filter_var($input['robots_follow'] ?? false, FILTER_VALIDATE_BOOL),
            ],
            'social' => array_map(fn ($network) => self::profile($network, (string) ($input[$network] ?? '')), array_combine(array_keys(self::SOCIAL), array_keys(self::SOCIAL))),
            default => throw new SiteError('Bilinmeyen ayar grubu.'),
        };
        $this->write($group, $clean, $actor, $group === 'seo' ? ['robots_index' => $clean['robots_index'], 'robots_follow' => $clean['robots_follow']] : []);
    }

    /**
     * Stores a group, audits which fields are set (never their values,
     * except the given details) and bumps the site cache version, so every
     * cached public page, setting and query is rebuilt.
     *
     * @param  array<string, mixed>  $clean
     * @param  array<string, mixed>  $details
     */
    private function write(string $group, array $clean, Actor $actor, array $details = []): void
    {
        DB::table('site_settings')->upsert([['key' => $group, 'value' => json_encode($clean), 'updated_by' => mb_substr($actor->type.':'.$actor->id, 0, 64), 'updated_at' => now()]],
            ['key'], ['value', 'updated_by', 'updated_at']);
        $this->audit->record(AuditAction::SiteSettingsChanged, 'site_settings', $group, ['fields' => array_keys(array_filter($clean, fn ($v) => $v !== '' && $v !== [] && $v !== false))] + $details, $actor);
        SiteContents::bump();
    }

    /**
     * A bare origin (scheme://host[:port]); https in production.
     */
    private static function origin(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            return '';
        }
        if (preg_match('#^(https?)://([a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*)(:[0-9]{2,5})?$#', strtolower($url), $m) !== 1) {
            throw new SiteError('Canonical adres yalnız şema ve alan adından oluşmalı (örn. https://pratiktools.com).');
        }
        if ($m[1] !== 'https' && app()->isProduction()) {
            throw new SiteError('Production\'da canonical adres https:// ile başlamalı.');
        }

        return strtolower($url);
    }

    private static function profile(string $network, string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $onNetwork = collect(self::SOCIAL[$network]['hosts'])->contains(fn ($h) => $host === $h || str_ends_with($host, '.'.$h));
        if (preg_match('#^https://[a-z0-9.-]+/[A-Za-z0-9._~%@/+-]{1,180}$#', $url) !== 1 || ! $onNetwork) {
            throw new SiteError(self::SOCIAL[$network]['label'].' adresi https:// ile başlayan ve '.implode(' / ', self::SOCIAL[$network]['hosts']).' alan adında bir profil adresi olmalı.');
        }

        return $url;
    }

    private function owner(string $slug): string
    {
        $slug = strtolower(trim($slug));

        return preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $slug) === 1 && $this->tenants->idBySlug($slug) !== null
            ? $slug : throw new SiteError('Etkin bir tenant adı (slug) girin.');
    }

    private function disk(string $disk): string
    {
        if (! array_key_exists($disk, (array) config('filesystems.disks'))) {
            throw new SiteError('Tanımlı bir depolama diski seçin.');
        }
        if (app()->isProduction() && ! self::persistentDisk($disk)) {
            throw new SiteError('Production\'da görseller kalıcı bir object storage diskinde tutulmalı.');
        }

        return $disk;
    }

    private static function id(string $value, string $pattern): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return preg_match($pattern, $value) === 1 ? $value : throw new SiteError("Geçersiz kimlik biçimi: {$value}");
    }
}
