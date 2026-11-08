<?php

namespace App\Operations;

/**
 * External prerequisites for live sending, derived from stored state (never
 * a hard-coded PASS), in two groups:
 *
 *  delivery     sending to the audience that already consented: the
 *               consent basis of the tenant's strategy, SES, OneSignal,
 *               Ticimax. PASS or BLOCKED (WAIT for an external step); every
 *               non-PASS row blocks live sending.
 *  acquisition  collecting new consents (İYS ViA OTP, İYS API access) when
 *               the tenant's strategy does not depend on them. PASS or WARN:
 *               shown, never blocking.
 *
 * With via_reconciliation the consent basis is ViA + reconciliation, so ViA
 * stays a delivery row there.
 */
final class ExternalBlockers
{
    public const DELIVERY = 'delivery';

    public const ACQUISITION = 'acquisition';

    public function __construct(private readonly IntegrationStatus $integrations) {}

    /**
     * Rows that block live sending (the delivery group, not PASS).
     *
     * @param  list<array{blocker: string, status: string, detail: string, action: ?string, group: string}>  $rows
     * @return list<array{blocker: string, status: string, detail: string, action: ?string, group: string}>
     */
    public static function blocking(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $r) => $r['group'] === self::DELIVERY && $r['status'] !== 'PASS'));
    }

    /**
     * İYS ViA. A delivery row only when the strategy is via_reconciliation;
     * otherwise "new consent channel" (acquisition): always shown for
     * merchant_verified, else once a ViA form is recorded.
     *
     * @param  array<string, array<string, mixed>>  $s
     * @return list<array{blocker: string, status: string, detail: string, action: ?string, group: string}>
     */
    private function via(array $s): array
    {
        $via = (array) ($s['via'] ?? []);
        $strategy = $s['iys']['strategy']['strategy'] ?? 'direct_api';
        $level = (string) ($via['level'] ?? 'BLOCKED');
        $action = $level === 'PASS' ? null : ReadinessCatalog::explain($via['blocker'] ?? 'VIA_NOT_CONFIGURED')['action'];

        if ($strategy === 'via_reconciliation') {
            return [['blocker' => 'IYS_VIA', 'status' => $level, 'detail' => (string) ($via['detail'] ?? ''), 'action' => $action, 'group' => self::DELIVERY]];
        }
        if (! ($via['configured'] ?? false) && $strategy !== 'merchant_verified') {
            return [];
        }
        $detail = 'Yeni izin toplama kanalı (ViA OTP)'.(($via['configured'] ?? false) ? ' · form: '.($via['form_status_label'] ?? '—') : ' · yapılandırılmadı')
            .' · '.($via['detail'] ?? '').' Mevcut izinli kitleye gönderimi engellemez.';

        return [['blocker' => 'IYS_VIA', 'status' => $level === 'PASS' ? 'PASS' : 'WARN', 'detail' => $detail, 'action' => $action, 'group' => self::ACQUISITION]];
    }

    /**
     * The consent basis follows the tenant's strategy:
     *  direct_api          İYS API access + F2 shadow sync (delivery);
     *  via_reconciliation  ViA + a fresh applied change report (delivery);
     *  merchant_verified   the attested store permissions (delivery), and
     *                      the missing İYS API access is shown as a warning.
     *
     * @param  array<string, mixed>  $iys
     * @param  callable(string, bool, string, string): array{blocker: string, status: string, detail: string, action: ?string, group: string}  $row
     * @return list<array{blocker: string, status: string, detail: string, action: ?string, group: string}>
     */
    private function iys(array $iys, callable $row): array
    {
        $strategy = (array) ($iys['strategy'] ?? []);
        $mode = $strategy['strategy'] ?? 'direct_api';
        $blocker = $strategy['blocker'] ?? null;

        if ($mode === 'direct_api') {
            return [$row('IYS_API_ACCESS', (bool) ($iys['consent_available'] ?? false), ($iys['configured'] ?? false) ? 'İYS integration '.$iys['status'] : 'No İYS integration',
                'Obtain İYS API access and complete the F2 shadow sync.')];
        }
        if ($mode === 'via_reconciliation') {
            $age = $strategy['reconciliation']['age_hours'] ?? null;

            return [$row('IYS_VIA_RECONCILIATION', $blocker === null,
                $blocker === null ? 'ViA configured · last reconciliation '.$age.' h ago' : ReadinessCatalog::explain($blocker)['explanation'],
                ReadinessCatalog::explain($blocker)['action'])];
        }

        $m = (array) ($strategy['merchant'] ?? []);
        $attestation = $m['attestation'] ?? null;
        $detail = $blocker === null
            ? 'Mağaza izni (operasyonel doğrulama, İYS doğrulaması değil) · beyan: '.($attestation['operator'] ?? '—').', '.substr((string) ($attestation['attested_at'] ?? ''), 0, 10)
                .' · Ticimax izin senkronu '.($m['sync_age_hours'] ?? '—').' saat önce'
            : ReadinessCatalog::explain($blocker)['explanation'];
        $rows = [$row('MERCHANT_CONSENT_BASIS', $blocker === null, $detail, ReadinessCatalog::explain($blocker)['action'])];
        if (! ($iys['consent_available'] ?? false)) {
            $rows[] = ['blocker' => 'IYS_API_ACCESS', 'status' => 'WARN', 'group' => self::ACQUISITION,
                'detail' => 'Bu müşteri için İYS API erişimi yok; izin dayanağı mağaza izni. Mevcut izinli kitleye gönderimi engellemez.', 'action' => null];
        }

        return $rows;
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $status
     * @return list<array{blocker: string, status: string, detail: string, action: ?string, group: string}>
     */
    public function all(?array $status = null): array
    {
        $s = $status ?? $this->integrations->all();
        $vault = fn (string $p) => ($s[$p]['vault_secret'] ?? false) && ($s[$p]['connected'] ?? false);
        $coverage = (array) ($s['onesignal']['external_id_coverage'] ?? ['with' => 0, 'total' => 0]);
        $row = fn (string $name, bool $ok, string $detail, string $action) => ['blocker' => $name, 'status' => $ok ? 'PASS' : 'BLOCKED', 'detail' => $detail, 'action' => $ok ? null : $action,
            'group' => self::DELIVERY];

        return [
            ...$this->iys($s['iys'] ?? [], $row),
            ...$this->via($s),
            $row('SES_PRODUCTION_ACCESS', ($s['amazon_ses']['production_access'] ?? null) === true,
                ($s['amazon_ses']['production_access'] ?? null) === null ? 'Account status unknown' : 'Sandbox (production access not granted)', 'Wait for AWS SES production access.'),
            $row('EMAIL_UNSUBSCRIBE_KEY', ($s['amazon_ses']['unsubscribe_key'] ?? 'MISSING') === 'CONFIGURED', (string) ($s['amazon_ses']['unsubscribe_key'] ?? 'MISSING'),
                'Add EMAIL_UNSUBSCRIBE_KEY as Laravel Cloud Secret.'),
            $row('SES_VAULT_CONNECTION', $vault('amazon_ses'), (string) ($s['amazon_ses']['status'] ?? 'not_configured'), 'Operator: email:connect-ses (secret via STDIN) in this environment.'),
            $row('ONESIGNAL_VAULT_CONNECTION', $vault('onesignal'), (string) ($s['onesignal']['status'] ?? 'not_configured'), 'Operator: onesignal:connect (secret via STDIN) in this environment.'),
            $row('TICIMAX_VAULT_CONNECTION', $vault('ticimax'), (string) ($s['ticimax']['status'] ?? 'not_configured'), 'Operator: ticimax:connect (secret via STDIN) in this environment.'),
            $row('ONESIGNAL_EXTERNAL_ID', (int) $coverage['total'] > 0 && (int) $coverage['with'] > 0, "External ID coverage: {$coverage['with']} / {$coverage['total']}",
                'Action required: Ticimax native app must identify logged-in users to OneSignal.'),
        ];
    }
}
