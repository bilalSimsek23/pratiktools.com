<?php

namespace App\Email;

use App\Consent\ConsentStrategy;
use App\Consent\SendEligibility;
use App\Consent\Via\ViaConfigurations;
use App\Consent\Via\ViaFrameReadiness;
use App\Dispatch\DispatchBlocked;
use App\Dispatch\DispatchGuard;
use App\Models\TenantIntegration;
use App\Operations\ReadinessCatalog;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-wide e-mail campaign readiness (the "11 Kasım E-posta Hazırlığı"
 * summary): could this tenant send a live marketing campaign to the
 * audience that already consented? It is not a campaign's own readiness
 * (CampaignReadiness); it adds what no single campaign shows: the consent
 * basis, live dispatch, the sending mode.
 *
 * Two groups:
 *  delivery     every check must PASS, else NO-GO: SES, sender, DNS,
 *               events, unsubscribe, suppression, the consent basis of
 *               the tenant's strategy, recipients that may be sent to
 *               now, a template, the sending mode, live dispatch.
 *  acquisition  collecting new consents (ViA Frame / OTP, İYS API access
 *               when the strategy does not use it): PASS or WARN, shown
 *               but never blocking.
 *
 * The consent basis is the tenant's strategy (ConsentStrategy): İYS API,
 * ViA + reconciliation, or attested store permissions (merchant_verified,
 * never shown as an İYS verification). The audience count is the
 * eligibility engine's own (SendEligibility), so it always agrees with what
 * the send-time guard would allow. Live dispatch is asked of DispatchGuard
 * itself, so the answer follows the code instead of a flag.
 */
final class EmailLaunchReadiness
{
    public const DELIVERY = 'delivery';

    public const ACQUISITION = 'acquisition';

    public const CHECKS = [
        'SES_READY' => [self::DELIVERY, 'SES altyapısı'],
        'SES_PRODUCTION_ACCESS' => [self::DELIVERY, 'SES production access'],
        'SENDER_VERIFIED' => [self::DELIVERY, 'Gönderici doğrulandı'],
        'DKIM' => [self::DELIVERY, 'DKIM'],
        'DMARC' => [self::DELIVERY, 'DMARC'],
        'SNS' => [self::DELIVERY, 'SNS (bounce/complaint olayları)'],
        'UNSUBSCRIBE' => [self::DELIVERY, 'Unsubscribe'],
        'SUPPRESSION' => [self::DELIVERY, 'Suppression'],
        'CONSENT_BASIS' => [self::DELIVERY, 'İzin dayanağı'],
        'AUDIENCE' => [self::DELIVERY, 'Gönderilebilir alıcı'],
        'TEMPLATE' => [self::DELIVERY, 'Yayınlanmış kampanya şablonu'],
        'SENDING_MODE' => [self::DELIVERY, 'Gönderim modu'],
        'LIVE_DISPATCH' => [self::DELIVERY, 'Canlı dispatch'],
        'VIA_FRAME' => [self::ACQUISITION, 'Yeni izin toplama kanalı (İYS ViA Frame)'],
        'IYS_API_ACCESS' => [self::ACQUISITION, 'İYS API erişimi'],
    ];

    /**
     * Audience breakdown order; LOCAL_SUPPRESSED is split by what
     * suppressed the address (complaint > hard bounce > unsubscribe/RET).
     */
    public const BREAKDOWN = [
        'ALLOWED' => 'Gönderilebilir',
        'UNSUBSCRIBE' => 'Abonelikten çıktı / RET (suppression)',
        'HARD_BOUNCE' => 'Hard bounce',
        'COMPLAINT' => 'Şikâyet (complaint)',
        'OTHER_SUPPRESSION' => 'Diğer suppression (manuel, soft bounce, geçersiz)',
        'MERCHANT_NOT_GRANTED' => 'Mağaza izni yok / geri çekilmiş',
        'MERCHANT_CONSENT_STALE' => 'Mağaza izin senkronu güncel değil',
        'IDENTITY_CONFLICT' => 'Adres birden fazla müşteride',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly SesReadiness $ses,
        private readonly ConsentStrategy $consent,
        private readonly SendEligibility $eligibility,
        private readonly ViaConfigurations $via,
        private readonly DispatchGuard $dispatch,
    ) {}

    /**
     * @param  array<string, mixed>|null  $ses  a SesReadiness result to reuse
     * @return array{sendable: bool, verdict: string, checks: array<string, array{label: string, group: string, status: string, detail: ?string}>, counts: array<string, int>, breakdown: array<string, int>, strategy: array<string, mixed>}
     */
    public function evaluate(?TenantIntegration $integration, ?array $ses = null): array
    {
        $tenantId = $this->context->requireTenant();
        $ses ??= $this->ses->evaluate($integration);
        $steps = $ses['steps'];
        $ok = fn (string $step) => $steps[$step]['state'] === 'ok';

        $breakdown = $this->breakdown($tenantId);
        $recipients = array_sum($breakdown);
        $allowed = $breakdown['ALLOWED'] ?? 0;
        $suppressed = (int) DB::table('suppressions')->where('tenant_id', $tenantId)->whereNull('lifted_at')
            ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', 'EMAIL'))->count();
        $templates = (int) DB::table('email_template_versions as v')->join('email_templates as t', fn ($j) => $j->on('t.id', '=', 'v.template_id')->on('t.tenant_id', '=', 'v.tenant_id'))
            ->where('v.tenant_id', $tenantId)->where('v.status', 'published')->where('t.message_type', EmailMessageType::Marketing->value)->count();
        $strategy = $this->consent->state();

        $live = true;
        try {
            $this->dispatch->assertMode('live', 'email', sendingMode: false);
        } catch (DispatchBlocked) {
            $live = false;
        }

        $c = [];
        $c['SES_READY'] = in_array($ses['overall'], ['infrastructure_ready', 'ready'], true) ? [true, $ses['overall_label']] : [false, 'SES hazırlık: '.$ses['overall_label']];
        $c['SES_PRODUCTION_ACCESS'] = [$ok('production_access'), $steps['production_access']['detail']];
        $c['SENDER_VERIFIED'] = [$ok('sender_identity'), $steps['sender_identity']['detail']];
        $c['DKIM'] = [$ok('dkim'), $steps['dkim']['detail']];
        $c['DMARC'] = [$ok('dmarc'), $steps['dmarc']['detail']];
        $c['SNS'] = [$ok('sns') && $ok('bounce_complaint'), $ok('sns') ? $steps['bounce_complaint']['detail'] : $steps['sns']['detail']];
        $c['UNSUBSCRIBE'] = [$ok('unsubscribe'), $steps['unsubscribe']['detail']];
        // Bounces and complaints suppress only once their events arrive.
        $c['SUPPRESSION'] = [$ok('bounce_complaint'), $ok('bounce_complaint') ? "{$suppressed} aktif suppression." : 'Bounce/complaint olayları gelmeden suppression güncellenmez.'];
        $c['CONSENT_BASIS'] = [$strategy['ready'], $this->basisDetail($strategy)];
        $c['AUDIENCE'] = [$allowed > 0, "{$allowed} / {$recipients} e-posta alıcısı şu an gönderilebilir (izin, suppression ve kimlik kurallarından sonra; kampanya segmenti ve sıklık sınırı ayrıca uygulanır)."];
        $c['TEMPLATE'] = [$templates > 0, $templates > 0 ? "{$templates} yayınlanmış pazarlama şablonu sürümü." : 'Yayınlanmış pazarlama şablonu yok.'];
        $c['SENDING_MODE'] = [$ses['mode']['effective'] === EmailSendingMode::Live->value, "Platform: {$ses['mode']['platform']}, müşteri: {$ses['mode']['tenant']}."];
        $c['LIVE_DISPATCH'] = [$live, $live ? null : 'Canlı dispatch henüz desteklenmiyor.'];
        $c += $this->acquisition($strategy);

        $checks = [];
        foreach (self::CHECKS as $key => [$group, $label]) {
            if (! isset($c[$key])) {
                continue;
            }
            $checks[$key] = ['label' => $label, 'group' => $group, 'status' => $c[$key][0] ? 'PASS' : ($group === self::ACQUISITION ? 'WARN' : 'BLOCKED'), 'detail' => $c[$key][1]];
        }
        $sendable = ! in_array('BLOCKED', array_column($checks, 'status'), true);

        return [
            'sendable' => $sendable,
            'verdict' => $sendable ? 'GO' : 'NO-GO',
            'checks' => $checks,
            'counts' => ['recipients' => $recipients, 'allowed' => $allowed, 'suppressed' => $suppressed, 'templates' => $templates],
            'breakdown' => $breakdown,
            'strategy' => ['strategy' => $strategy['strategy'], 'label' => $strategy['label'], 'source_label' => $strategy['source_label']],
        ];
    }

    /**
     * E-mail recipients per eligibility reason, LOCAL_SUPPRESSED split by
     * its suppression kind; the named reasons first, then any other reason
     * by its own code.
     *
     * @return array<string, int>
     */
    private function breakdown(string $tenantId): array
    {
        $rows = DB::select(
            "SELECT CASE WHEN e.reason <> 'LOCAL_SUPPRESSED' THEN e.reason ELSE (
                    SELECT CASE
                        WHEN bool_or(s.source IN ('SES_COMPLAINT', 'KLAVIYO_SPAM')) THEN 'COMPLAINT'
                        WHEN bool_or(s.source IN ('SES_BOUNCE', 'KLAVIYO_BOUNCE')) THEN 'HARD_BOUNCE'
                        WHEN bool_or(s.source IN ('UNSUBSCRIBE', 'KLAVIYO_UNSUBSCRIBE', 'IYS_RET')) THEN 'UNSUBSCRIBE'
                        ELSE 'OTHER_SUPPRESSION' END
                    FROM suppressions s WHERE s.tenant_id = ? AND s.recipient_id = e.id AND s.lifted_at IS NULL AND (s.channel IS NULL OR s.channel = 'EMAIL')
                ) END AS bucket, count(*) AS n
             FROM ({$this->eligibility->sql()}) e GROUP BY 1",
            [$tenantId, ...$this->eligibility->bindings()],
        );
        $counts = collect($rows)->mapWithKeys(fn ($r) => [(string) $r->bucket => (int) $r->n])->all();
        $named = [];
        foreach (array_keys(self::BREAKDOWN) as $code) {
            $named[$code] = (int) ($counts[$code] ?? 0);
        }
        $rest = array_diff_key($counts, self::BREAKDOWN);
        ksort($rest);

        return $named + $rest;
    }

    /**
     * @param  array<string, mixed>  $strategy
     */
    private function basisDetail(array $strategy): string
    {
        $label = "{$strategy['label']}.";
        if (! $strategy['ready']) {
            return "{$label} Hazır değil: ".ReadinessCatalog::explain($strategy['blocker'])['explanation']." ({$strategy['blocker']})";
        }
        if ($strategy['strategy'] !== 'merchant_verified') {
            return "{$label} İzin kararı {$strategy['source_label']} verisinden.";
        }
        $m = (array) $strategy['merchant'];

        return "{$label} Beyan: ".($m['attestation']['operator'] ?? '—').', '.substr((string) ($m['attestation']['attested_at'] ?? ''), 0, 10)
            .' · ispat: '.($m['attestation']['evidence_ref'] ?? '—').' · Ticimax izin senkronu '.($m['sync_age_hours'] ?? '—').' saat önce (en fazla '.($m['max_age_hours'] ?? '—').').';
    }

    /**
     * New consent collection; never blocks sending to the audience that
     * already consented. İYS API access is listed only when the strategy
     * does not depend on it (direct_api shows it as the consent basis).
     *
     * @param  array<string, mixed>  $strategy
     * @return array<string, array{0: bool, 1: string}>
     */
    private function acquisition(array $strategy): array
    {
        $config = $this->via->current();
        $frame = ViaFrameReadiness::of($config);
        // Ready = complete configuration plus the Super Admin's confirmation
        // of İYS's domain activation; Frame events never count. A WARN at
        // most: the acquisition group never blocks sending.
        $missing = collect($frame->checks)->reject(fn ($check) => $check['ok'])->pluck('label')->implode(', ');
        $c = ['VIA_FRAME' => $frame->ready()
            ? [true, 'ViA Frame hazır: '.$config?->activatedOrigin().' için İYS domain aktivasyonu teyitli. Frame olayları izin kaydı oluşturmaz.']
            : [false, 'ViA Frame: '.$frame->label().($missing !== '' ? " (eksik: {$missing})" : '').'. Mevcut izinli kitleye gönderimi engellemez.']];
        if ($strategy['strategy'] !== 'direct_api') {
            $c['IYS_API_ACCESS'] = $strategy['direct_api']['available']
                ? [true, 'İYS API durum senkronu var.']
                : [false, 'İYS API erişimi yok; bu stratejide gerekli değil. Mevcut izinli kitleye gönderimi engellemez.'];
        }

        return $c;
    }
}
