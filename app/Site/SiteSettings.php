<?php

namespace App\Site;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Website settings. Analytics providers are configured by validated IDs
 * only (never pasted script): the site renders fixed, reviewed snippets
 * and loads them only after the visitor accepts analytics cookies.
 *
 * Groups:
 *  organization  name, email, phone, address, same_as (profile URLs), kvkk_url
 *  analytics     ga4_id (G-...), google_ads_id (AW-...), google_ads_lead_label, meta_pixel_id (digits)
 *  crawlers      gptbot: allow|disallow (model training; separate from search)
 */
final class SiteSettings
{
    private const DEFAULTS = [
        'organization' => ['name' => 'PratikTools', 'email' => '', 'phone' => '', 'address' => '', 'same_as' => [], 'kvkk_url' => ''],
        'analytics' => ['ga4_id' => '', 'google_ads_id' => '', 'google_ads_lead_label' => '', 'meta_pixel_id' => ''],
        'crawlers' => ['gptbot' => 'disallow'],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

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
            default => throw new SiteError('Bilinmeyen ayar grubu.'),
        };
        DB::table('site_settings')->upsert([['key' => $group, 'value' => json_encode($clean), 'updated_by' => mb_substr($actor->type.':'.$actor->id, 0, 64), 'updated_at' => now()]],
            ['key'], ['value', 'updated_by', 'updated_at']);
        $this->audit->record(AuditAction::SiteSettingsChanged, 'site_settings', $group, ['fields' => array_keys(array_filter($clean, fn ($v) => $v !== '' && $v !== []))], $actor);
        SiteContents::bump();
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
