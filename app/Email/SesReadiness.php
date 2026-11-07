<?php

namespace App\Email;

use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * E-posta / SES Hazırlık: where the current tenant's SES setup stands, one
 * step at a time, from what is stored (the last account check, sender
 * refresh, SNS state, test e-mail). It makes no provider call.
 *
 * Step states: ok | warn (advisory, never blocks) | pending | missing |
 * failed | blocked. Overall:
 *
 *   not_configured        no SES integration
 *   awaiting_test         saved, never (or no longer) tested
 *   action_required       a failure someone must fix
 *   incomplete            a required step is missing or pending
 *   infrastructure_ready  everything required is in place, live sending off
 *   ready                 the same and the effective sending mode is live
 *
 * "ready" never appears while the effective sending mode is not live. While
 * the mode is off the test e-mail cannot be sent; that step is "blocked"
 * and does not hold back infrastructure_ready, which then says so.
 */
final class SesReadiness
{
    public const STEPS = [
        'aws_connection' => 'AWS bağlantısı',
        'production_access' => 'Production access',
        'sender_identity' => 'Gönderici kimliği',
        'dkim' => 'DKIM',
        'dmarc' => 'DMARC',
        'mail_from' => 'MAIL FROM',
        'configuration_set' => 'Configuration Set',
        'sns' => 'SNS webhook',
        'bounce_complaint' => 'Bounce / Complaint',
        'unsubscribe' => 'Unsubscribe',
        'test_recipient' => 'Test alıcısı',
        'test_email' => 'Test e-postası',
        'sending_mode' => 'Gönderim modu',
    ];

    public const OVERALL = [
        'not_configured' => 'Yapılandırılmadı',
        'awaiting_test' => 'Test bekliyor',
        'action_required' => 'Müdahale gerekli',
        'incomplete' => 'Eksik',
        'infrastructure_ready' => 'Altyapı hazır / Canlı gönderim kapalı',
        'ready' => 'Hazır',
    ];

    /** Steps that never hold back infrastructure_ready. */
    private const ADVISORY = ['mail_from', 'sending_mode'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly EmailSendGuard $guard,
        private readonly UnsubscribeTokens $unsubscribe,
        private readonly SnsSubscriptions $sns,
        private readonly TestEmails $tests,
    ) {}

    /**
     * @return array{overall: string, overall_label: string, steps: array<string, array{label: string, state: string, detail: ?string}>, sender: ?object, mode: array{platform: string, tenant: string, effective: string, reason: string}}
     */
    public function evaluate(?TenantIntegration $integration): array
    {
        $tenantId = $this->context->requireTenant();
        $mode = $this->modes();
        $unsubscribe = $this->unsubscribe();
        if ($integration === null) {
            $steps = array_map(fn (string $label) => ['label' => $label, 'state' => 'missing', 'detail' => null], self::STEPS);
            $steps['unsubscribe'] = $this->unsubscribeStep($unsubscribe);
            $steps['sending_mode'] = $this->modeStep($mode);

            return ['overall' => 'not_configured', 'overall_label' => self::OVERALL['not_configured'], 'steps' => $steps, 'sender' => null, 'mode' => $mode];
        }

        $settings = (array) $integration->settings;
        $account = (array) ($settings['account'] ?? []);
        $set = (array) ($settings['configuration_set_check'] ?? []);
        $sns = $this->sns->state($integration);
        $sender = $this->sender($tenantId, $integration);
        $s = [];

        $s['aws_connection'] = match ($integration->status) {
            'connected' => ['ok', 'Son doğrulama başarılı.'],
            'action_required' => ['failed', 'AWS kimlik bilgileri veya IAM yetkileri reddedildi.'],
            'error' => ['failed', 'Son test başarısız.'],
            default => ['pending', 'Bağlantı testi bekleniyor.'],
        };
        $s['production_access'] = match (true) {
            $account === [] => ['pending', 'Hesap bilgisi yok; bağlantıyı test edin.'],
            ($account['enforcement_status'] ?? null) !== null && $account['enforcement_status'] !== 'HEALTHY' => ['failed', 'SES hesap durumu: '.$account['enforcement_status']],
            ($account['sending_enabled'] ?? false) !== true => ['failed', 'SES hesabında gönderim kapalı.'],
            ($account['production_access'] ?? false) !== true => ['failed', 'SES hesabı sandbox modunda.'],
            default => ['ok', null],
        };
        $s['sender_identity'] = match ($sender?->verification_status) {
            null => ['missing', 'Gönderici eklenmedi.'],
            'verified' => ['ok', $sender->from_email],
            'failed' => ['failed', 'SES doğrulaması başarısız.'],
            default => ['pending', $sender->last_checked_at === null ? 'Doğrula / Yenile ile durumu okuyun.' : 'SES doğrulaması bekleniyor (DNS).'],
        };
        $s['dkim'] = match ($sender?->dkim_status) {
            null => ['missing', null],
            'verified' => ['ok', null],
            'failed' => ['failed', 'DKIM doğrulaması başarısız.'],
            'disabled' => ['missing', 'SES\'te DKIM imzalama kapalı.'],
            default => ['pending', 'DKIM CNAME kayıtları bekleniyor.'],
        };
        $s['dmarc'] = match (true) {
            $sender === null => ['missing', null],
            str_starts_with((string) $sender->dmarc_status, 'p=') && $sender->dmarc_status !== 'p=invalid' => ['ok', $sender->dmarc_status],
            $sender->dmarc_status === 'missing' => ['missing', '_dmarc kaydı yok.'],
            $sender->dmarc_status === 'p=invalid' => ['failed', 'DMARC kaydı geçersiz.'],
            default => ['pending', 'DMARC henüz okunmadı.'],
        };
        $s['mail_from'] = $sender?->mail_from_status === 'verified' ? ['ok', $sender->mail_from_domain] : ['warn', 'Özel MAIL FROM yok: SPF From alanıyla hizalanmaz (önerilir, zorunlu değil).'];
        $s['configuration_set'] = match (true) {
            ($settings['configuration_set'] ?? null) === null => ['missing', 'Configuration Set adı girilmedi.'],
            ($set['state'] ?? null) === 'ok' && ($set['sending_enabled'] ?? true) === false => ['failed', 'Configuration Set\'te gönderim kapalı.'],
            ($set['state'] ?? null) === 'ok' => ['ok', (string) $settings['configuration_set']],
            ($set['state'] ?? null) === 'missing' => ['failed', 'Bu adla bir Configuration Set SES\'te yok.'],
            ($set['state'] ?? null) === 'iam_required' => ['warn', 'Doğrulanamadı – IAM yetkisi gerekli (ses:GetConfigurationSet).'],
            ($set['state'] ?? null) === 'error' => ['warn', 'Doğrulanamadı – SES yanıt vermedi.'],
            default => ['pending', 'Bağlantıyı test edin.'],
        };
        $s['sns'] = match (true) {
            ($settings['sns_topic_arns'] ?? []) === [] => ['missing', 'SNS Topic ARN girilmedi.'],
            $sns['state'] === 'confirmed' && $sns['topic_allowed'] => ['ok', null],
            $sns['state'] === 'confirmed' => ['failed', 'Onaylı abonelik kayıtlı topic\'lerden birine ait değil.'],
            $sns['state'] === 'error' => ['failed', 'Abonelik onayı başarısız.'],
            $sns['state'] === 'pending_confirmation' => ['pending', $sns['expired'] ? 'Onay süresi doldu; AWS\'den onay isteğini yeniden gönderin.' : 'Abonelik onay bekliyor.'],
            default => ['missing', 'SNS aboneliği henüz gelmedi.'],
        };
        $receivedBounce = DB::table('email_events')->where('tenant_id', $tenantId)->where('provider', 'amazon_ses')->whereIn('event_type', ['EMAIL_BOUNCED', 'EMAIL_COMPLAINT'])->exists();
        $s['bounce_complaint'] = match (true) {
            $s['sns'][0] !== 'ok' => ['missing', 'Önce SNS aboneliği.'],
            ($set['bounce_complaint'] ?? false) === true || $receivedBounce => ['ok', $receivedBounce ? 'Bounce/complaint olayı alındı.' : 'Configuration Set BOUNCE ve COMPLAINT olaylarını SNS\'e gönderiyor.'],
            ($set['state'] ?? null) === 'ok' => ['missing', 'Configuration Set\'te BOUNCE ve COMPLAINT için SNS hedefi yok.'],
            ($set['state'] ?? null) === 'iam_required' => ['warn', 'Doğrulanamadı – IAM yetkisi gerekli (ses:GetConfigurationSetEventDestinations).'],
            default => ['pending', 'Bağlantıyı test edin.'],
        };
        $s['unsubscribe'] = array_values(array_intersect_key($this->unsubscribeStep($unsubscribe), ['state' => 1, 'detail' => 1]));
        $s['test_recipient'] = DB::table('test_recipients')->where('channel', 'email')->where('provider', '*')->where('active', true)->exists() ? ['ok', null] : ['missing', 'Aktif test alıcısı yok.'];
        $last = $this->tests->last($integration);
        $blocker = $this->tests->blocker($integration);
        $s['test_email'] = match (true) {
            $last !== null && $last['status'] === 'sent' => ['ok', 'Son test gönderildi'.($last['delivery_status'] ? ' ('.$last['delivery_status'].')' : '').'.'],
            in_array($blocker['code'] ?? null, ['SENDING_MODE_OFF', 'TENANT_SENDING_MODE_OFF'], true) => ['blocked', $blocker['message']],
            $last !== null && in_array($last['status'], ['blocked', 'failed'], true) => ['failed', 'Son test: '.$last['status'].($last['block_reason'] ? " ({$last['block_reason']})" : '')],
            $last !== null => ['pending', 'Test kuyrukta.'],
            default => ['missing', $blocker['message'] ?? 'Test e-postası gönderilmedi.'],
        };
        $s['sending_mode'] = array_values(array_intersect_key($this->modeStep($mode), ['state' => 1, 'detail' => 1]));

        $steps = [];
        foreach (self::STEPS as $key => $label) {
            $steps[$key] = ['label' => $label, 'state' => $s[$key][0], 'detail' => $s[$key][1]];
        }

        $overall = $this->overall($integration, $steps, $mode);

        return ['overall' => $overall, 'overall_label' => self::OVERALL[$overall], 'steps' => $steps, 'sender' => $sender, 'mode' => $mode];
    }

    /**
     * EMAIL_UNSUBSCRIBE_KEY is a platform secret (Cloud environment): only
     * whether it is usable is reported, never the value.
     *
     * @return array{configured: bool, base_url: string, https: bool, rfc8058: bool, routes: bool, round_trip: bool, ready: bool}
     */
    public function unsubscribe(): array
    {
        $base = (string) config('email.unsubscribe.base_url');
        $configured = $this->unsubscribe->available();
        $https = str_starts_with($base, 'https://') && filter_var($base, FILTER_VALIDATE_URL) !== false;
        $routes = Route::has('email.unsubscribe.show') && Route::has('email.unsubscribe.apply');
        $headers = $this->unsubscribe->headers('https://example.invalid/u/x');
        $rfc8058 = ($headers['List-Unsubscribe-Post'] ?? null) === 'List-Unsubscribe=One-Click' && str_starts_with($headers['List-Unsubscribe'] ?? '', '<https://');
        $roundTrip = false;
        if ($configured) {
            try {
                $tenant = '00000000-0000-0000-0000-000000000000';
                $roundTrip = $this->unsubscribe->verify($this->unsubscribe->issue($tenant, $tenant))['tenant_id'] === $tenant;
            } catch (Throwable) {
                $roundTrip = false;
            }
        }

        return ['configured' => $configured, 'base_url' => $base, 'https' => $https, 'rfc8058' => $rfc8058, 'routes' => $routes, 'round_trip' => $roundTrip,
            'ready' => $configured && $https && $rfc8058 && $routes && $roundTrip];
    }

    /**
     * @return array{platform: string, tenant: string, effective: string, reason: string}
     */
    public function modes(): array
    {
        $tenant = (string) (DB::table('tenant_settings')->value('sending_mode') ?? 'off');

        return ['platform' => EmailSendingMode::platform()->value, 'tenant' => $tenant, 'effective' => $this->guard->mode()->value,
            'reason' => PlatformEmailMode::explain(EmailSendingMode::ceiling(), app(PlatformEmailMode::class)->stored(), $tenant)];
    }

    /**
     * @param  array<string, array{label: string, state: string, detail: ?string}>  $steps
     * @param  array{platform: string, tenant: string, effective: string, reason: string}  $mode
     */
    private function overall(TenantIntegration $integration, array $steps, array $mode): string
    {
        $required = array_diff_key($steps, array_flip(self::ADVISORY));
        // While sending is off the test e-mail cannot happen; it is not the operator's gap.
        if ($mode['effective'] === EmailSendingMode::Off->value && $required['test_email']['state'] === 'blocked') {
            unset($required['test_email']);
        }
        $states = array_column($required, 'state');

        return match (true) {
            $integration->status === 'pending' => 'awaiting_test',
            in_array('failed', $states, true) => 'action_required',
            array_diff($states, ['ok', 'warn']) !== [] => 'incomplete',
            $mode['effective'] === EmailSendingMode::Live->value && $steps['test_email']['state'] === 'ok' => 'ready',
            default => 'infrastructure_ready',
        };
    }

    /**
     * @param  array<string, mixed>  $u
     * @return array{label: string, state: string, detail: ?string}
     */
    private function unsubscribeStep(array $u): array
    {
        return ['label' => self::STEPS['unsubscribe']] + match (true) {
            ! $u['configured'] => ['state' => 'blocked', 'detail' => 'BLOCKED: EMAIL_UNSUBSCRIBE_KEY platform ortamında tanımlı değil.'],
            ! $u['https'] => ['state' => 'blocked', 'detail' => 'BLOCKED: unsubscribe adresi https değil.'],
            ! $u['ready'] => ['state' => 'blocked', 'detail' => 'BLOCKED: unsubscribe bağlantıları doğrulanamadı.'],
            default => ['state' => 'ok', 'detail' => null],
        };
    }

    /**
     * @param  array{platform: string, tenant: string, effective: string, reason: string}  $mode
     * @return array{label: string, state: string, detail: ?string}
     */
    private function modeStep(array $mode): array
    {
        return ['label' => self::STEPS['sending_mode']] + ($mode['effective'] === EmailSendingMode::Live->value
            ? ['state' => 'ok', 'detail' => 'Canlı gönderim açık.']
            : ['state' => 'blocked', 'detail' => "Canlı gönderim kapalı (platform: {$mode['platform']}, müşteri: {$mode['tenant']})."]);
    }

    /**
     * The sender the readiness is about: the best verified one, else the
     * most recently checked.
     */
    private function sender(string $tenantId, TenantIntegration $integration): ?object
    {
        return DB::table('email_sender_identities')->where('tenant_id', $tenantId)->where('integration_id', $integration->id)
            ->orderByRaw("CASE verification_status WHEN 'verified' THEN 0 ELSE 1 END")->orderByRaw("CASE dkim_status WHEN 'verified' THEN 0 ELSE 1 END")
            ->orderByDesc('last_checked_at')->orderBy('from_email')
            ->first(['id', 'from_email', 'from_name', 'domain', 'verification_status', 'dkim_status', 'mail_from_domain', 'mail_from_status', 'spf_status', 'dmarc_status', 'last_checked_at']);
    }
}
