<?php

namespace App\Tenancy;

/**
 * Integration setup status of a tenant from stored integration rows only
 * (provider + status; never a secret or a setting). A new tenant starts
 * with every provider "not configured"; nothing is assumed ready.
 */
final class TenantOnboarding
{
    public const PROVIDERS = [
        'ticimax' => 'Ticimax bağlantısı',
        'iys' => 'İYS / ViA yapılandırması',
        'amazon_ses' => 'E-posta gönderimi (Amazon SES)',
        'onesignal' => 'Push bildirimleri (OneSignal)',
    ];

    public const LABELS = [
        'not_configured' => 'Yapılandırılmadı',
        'configured' => 'Yapılandırıldı (doğrulanmadı)',
        'waiting' => 'Bekliyor',
        'blocked' => 'Engelli',
        'ready' => 'Hazır',
    ];

    private const RANK = ['not_configured' => 0, 'blocked' => 1, 'waiting' => 2, 'configured' => 3, 'ready' => 4];

    /**
     * @param  iterable<object{provider: string, status: string}>  $rows  tenant_integrations of one tenant
     * @return array<string, array{label: string, state: string, state_label: string}>
     */
    public static function summarize(iterable $rows): array
    {
        $states = array_fill_keys(array_keys(self::PROVIDERS), 'not_configured');
        foreach ($rows as $row) {
            // ViA (consent acquisition) is part of the İYS / ViA step.
            $provider = $row->provider === 'via' ? 'iys' : $row->provider;
            if (! isset($states[$provider])) {
                continue;
            }
            $state = self::state((string) $row->status);
            // Several capabilities per provider: the best one counts.
            if (self::RANK[$state] > self::RANK[$states[$provider]]) {
                $states[$provider] = $state;
            }
        }

        return array_map(fn ($p) => ['label' => self::PROVIDERS[$p], 'state' => $states[$p], 'state_label' => self::LABELS[$states[$p]]], array_combine(array_keys($states), array_keys($states)));
    }

    /**
     * @param  array<string, array{state: string}>  $summary
     */
    public static function readyCount(array $summary): int
    {
        return count(array_filter($summary, fn ($s) => $s['state'] === 'ready'));
    }

    public static function state(string $status): string
    {
        return match ($status) {
            'connected', 'syncing' => 'ready',
            'pending' => 'configured',
            'action_required' => 'waiting',
            'error' => 'blocked',
            default => 'not_configured',
        };
    }
}
