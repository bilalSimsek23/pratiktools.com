<?php

namespace App\Panel;

/**
 * Human-readable description of an automation version (no JSON for users).
 */
final class AutomationPresenter
{
    private const TRIGGERS = [
        'cart_abandoned' => 'Sepet terk edildi',
        'order_created' => 'Sipariş oluşturuldu',
        'order_completed' => 'Sipariş teslim edildi',
        'customer_created' => 'Yeni üye',
        'newsletter_subscribed' => 'Bülten kaydı',
        'consent_changed' => 'İzin durumu değişti',
        'push_subscription_changed' => 'Push aboneliği değişti',
        'no_purchase_for' => 'Belirli süredir alışveriş yok',
        'segment_periodic' => 'Segment periyodik kontrol',
    ];

    private const CONDITIONS = [
        'no_order_since_trigger' => 'Bu sürede sipariş verdi mi? (verdiyse çık)',
        'cart_still_open' => 'Sepet hâlâ açık mı?',
        'cart_items_available' => 'Ürünler hâlâ satışta ve stokta mı?',
        'customer_rule' => 'Müşteri kuralı sağlanıyor mu?',
    ];

    /**
     * @param  array<string, mixed>  $trigger
     */
    public static function trigger(array $trigger): string
    {
        $label = self::TRIGGERS[$trigger['type'] ?? ''] ?? (string) ($trigger['type'] ?? '?');
        $p = (array) ($trigger['params'] ?? []);

        return match ($trigger['type'] ?? null) {
            'no_purchase_for' => "Son siparişin üzerinden {$p['days']} gün geçti",
            'segment_periodic' => "{$label} (her {$p['reentry_days']} günde bir)",
            default => $label,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array{kind: string, title: string, detail: string}>
     */
    public static function steps(array $steps): array
    {
        return array_map(function (array $s) {
            $p = (array) ($s['params'] ?? []);
            $channel = fn (string $c) => $c === 'email' ? 'E-posta' : 'Push';
            $type = fn (?string $t) => $t === 'transactional' ? 'işlemsel' : 'pazarlama';

            return match ($s['type']) {
                'delay' => ['kind' => 'wait', 'title' => 'Bekle', 'detail' => self::duration((int) $p['minutes'])],
                'condition' => ['kind' => 'check', 'title' => 'Kontrol', 'detail' => self::CONDITIONS[$p['check']] ?? (string) $p['check']],
                'segment_check' => ['kind' => 'check', 'title' => 'Segment kontrolü', 'detail' => 'Müşteri seçili segmentte mi?'],
                'consent_check' => ['kind' => 'check', 'title' => 'İzin kontrolü', 'detail' => $channel((string) $p['channel']).' için izin uygun mu?'],
                'frequency_check' => ['kind' => 'check', 'title' => 'Sıklık sınırı', 'detail' => $channel((string) $p['channel']).' sıklık sınırı aşılmadı mı?'],
                'create_email_intent' => ['kind' => 'action', 'title' => 'E-posta', 'detail' => 'Planlı '.$type($p['message_type'] ?? null).' e-postası (gönderim anında izin tekrar kontrol edilir)'],
                'create_push_intent' => ['kind' => 'action', 'title' => 'Push', 'detail' => 'Planlı push'.(($p['only_if_no_intent'] ?? false) ? ' (e-posta uygun değilse)' : '')],
                'exit' => ['kind' => 'exit', 'title' => 'Bitir', 'detail' => 'Akıştan çık'],
                default => ['kind' => 'check', 'title' => (string) $s['type'], 'detail' => ''],
            };
        }, $steps);
    }

    public static function duration(int $minutes): string
    {
        return match (true) {
            $minutes % 1440 === 0 => ($minutes / 1440).' gün',
            $minutes % 60 === 0 => ($minutes / 60).' saat',
            default => "{$minutes} dakika",
        };
    }
}
