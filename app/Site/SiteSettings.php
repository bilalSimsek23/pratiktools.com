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
            default => throw new SiteError('Bilinmeyen ayar grubu.'),
        };
        DB::table('site_settings')->upsert([['key' => $group, 'value' => json_encode($clean), 'updated_by' => mb_substr($actor->type.':'.$actor->id, 0, 64), 'updated_at' => now()]],
            ['key'], ['value', 'updated_by', 'updated_at']);
        $this->audit->record(AuditAction::SiteSettingsChanged, 'site_settings', $group, ['fields' => array_keys(array_filter($clean, fn ($v) => $v !== '' && $v !== []))], $actor);
        SiteContents::bump();
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
