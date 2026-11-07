<?php

namespace App\Email;

use App\Consent\ConsentChannel;
use App\Consent\ConsentSourceProvider;
use App\Consent\ConsentStatus;
use App\Consent\ConsentStrategy;
use App\Dispatch\DispatchBlocked;
use App\Dispatch\DispatchGuard;
use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-wide e-mail campaign readiness (the "11 Kasım E-posta Hazırlığı"
 * summary): could this tenant send a live marketing campaign at all? It is
 * not a campaign's own readiness (CampaignReadiness); it adds what no single
 * campaign shows: official İYS consent, live dispatch, the sending mode.
 *
 * It never says GO by default: every check must PASS. Official consent
 * counts only the official sources (İYS API, ViA, İYS reconciliation);
 * Ticimax MailIzin and other legacy sources never stand in for İYS ONAY.
 * Live dispatch is asked of DispatchGuard itself, so the answer follows
 * the code instead of a flag.
 */
final class EmailLaunchReadiness
{
    public const CHECKS = [
        'SES_READY' => 'SES altyapısı',
        'SES_PRODUCTION_ACCESS' => 'SES production access',
        'SENDER_VERIFIED' => 'Gönderici doğrulandı',
        'DKIM' => 'DKIM',
        'DMARC' => 'DMARC',
        'SNS' => 'SNS (bounce/complaint olayları)',
        'UNSUBSCRIBE' => 'Unsubscribe',
        'SUPPRESSION' => 'Suppression',
        'IYS_OFFICIAL_CONSENT' => 'Resmi İYS onayı',
        'AUDIENCE' => 'Hedef kitle',
        'TEMPLATE' => 'Yayınlanmış kampanya şablonu',
        'SENDING_MODE' => 'Gönderim modu',
        'LIVE_DISPATCH' => 'Canlı dispatch',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly SesReadiness $ses,
        private readonly ConsentStrategy $consent,
        private readonly DispatchGuard $dispatch,
    ) {}

    /**
     * @param  array<string, mixed>|null  $ses  a SesReadiness result to reuse
     * @return array{sendable: bool, verdict: string, checks: array<string, array{label: string, status: string, detail: ?string}>, counts: array<string, int>}
     */
    public function evaluate(?TenantIntegration $integration, ?array $ses = null): array
    {
        $tenantId = $this->context->requireTenant();
        $ses ??= $this->ses->evaluate($integration);
        $steps = $ses['steps'];
        $ok = fn (string $step) => $steps[$step]['state'] === 'ok';

        $official = (int) DB::table('consents')->where('tenant_id', $tenantId)->where('channel', ConsentChannel::Email->value)
            ->whereIn('source_provider', ConsentSourceProvider::OFFICIAL)->where('status', ConsentStatus::Granted->value)->distinct()->count('recipient_id');
        $audience = (int) DB::table('consent_recipients')->where('tenant_id', $tenantId)->where('address_type', 'email')->count();
        $suppressed = (int) DB::table('suppressions')->where('tenant_id', $tenantId)->whereNull('lifted_at')
            ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', ConsentChannel::Email->value))->count();
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
        $c['IYS_OFFICIAL_CONSENT'] = match (true) {
            ! $strategy['ready'] => [false, 'Canlı kampanya için BLOCKED: resmi İYS verisi hazır değil ('.$strategy['blocker'].'). Ticimax MailIzin İYS ONAY yerine kullanılmaz.'],
            $official === 0 => [false, 'Canlı kampanya için BLOCKED: resmi İYS ONAY kaydı yok. Ticimax MailIzin İYS ONAY yerine kullanılmaz.'],
            default => [true, "{$official} alıcıda resmi İYS ONAY."],
        };
        $c['AUDIENCE'] = [$audience > 0, "{$audience} e-posta alıcısı; resmi ONAY: {$official}."];
        $c['TEMPLATE'] = [$templates > 0, $templates > 0 ? "{$templates} yayınlanmış pazarlama şablonu sürümü." : 'Yayınlanmış pazarlama şablonu yok.'];
        $c['SENDING_MODE'] = [$ses['mode']['effective'] === EmailSendingMode::Live->value, "Platform: {$ses['mode']['platform']}, müşteri: {$ses['mode']['tenant']}."];
        $c['LIVE_DISPATCH'] = [$live, $live ? null : 'Canlı dispatch henüz desteklenmiyor.'];

        $checks = [];
        foreach (self::CHECKS as $key => $label) {
            $checks[$key] = ['label' => $label, 'status' => $c[$key][0] ? 'PASS' : 'BLOCKED', 'detail' => $c[$key][1]];
        }
        $sendable = ! in_array('BLOCKED', array_column($checks, 'status'), true);

        return [
            'sendable' => $sendable,
            'verdict' => $sendable ? 'GO' : 'NO-GO',
            'checks' => $checks,
            'counts' => ['audience' => $audience, 'official_consent' => $official, 'suppressed' => $suppressed, 'templates' => $templates],
        ];
    }
}
