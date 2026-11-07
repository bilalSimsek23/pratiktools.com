<?php

namespace App\Platform;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Email\Data\ConnectionResult;
use App\Email\EmailLaunchReadiness;
use App\Email\EmailSendBlocked;
use App\Email\InvalidEmailMessage;
use App\Email\SenderIdentities;
use App\Email\SesAccountCheck;
use App\Email\SesIntegrations;
use App\Email\SesReadiness;
use App\Email\SnsConfirmationFailed;
use App\Email\SnsSubscriptions;
use App\Email\TestEmails;
use App\Email\TestRecipients;
use App\Integrations\Errors\ProviderError;
use App\Models\PlatformAdmin;
use App\Models\TenantIntegration;
use App\Tenancy\ProvisioningError;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Super Admin › Müşteriler › Entegrasyonlar › Amazon SES: the tenant's SES
 * e-mail integration (provider "amazon_ses", capability "email"), read and
 * changed only inside that tenant's context (runtime role, RLS), through the
 * same services the email:* commands use. The platform role gets no access
 * to integration settings, secrets, senders, test recipients or events.
 *
 * The IAM key pair is written through SesIntegrations into the vault and
 * never read back here: the screen learns only whether a pair is stored and
 * when it last changed. Nothing here creates or changes anything at AWS or
 * in DNS, and nothing sends unless the existing send guard allows it.
 */
final class TenantSes
{
    private const SECRET_ACTIONS = [AuditAction::IntegrationSecretCreated, AuditAction::IntegrationSecretChanged];

    private const ERRORS = [
        'INVALID_REGION' => 'Bölge geçersiz (ör. eu-central-1).',
        'INVALID_CONFIGURATION_SET' => 'Configuration Set adı yalnız harf, rakam, "_" ve "-" içerebilir (en fazla 64).',
        'INVALID_TOPIC_ARN' => 'SNS Topic ARN geçersiz (arn:aws:sns:<bölge>:<hesap>:<ad>).',
        'TOPIC_REGION_MISMATCH' => 'SNS Topic, SES ile aynı bölgede olmalı (ör. ikisi de eu-central-1).',
        'INVALID_TRACKING' => 'Geçersiz izleme seçeneği.',
        'INVALID_ROLE' => 'Geçersiz rol.',
        'KEY_PAIR_REQUIRED' => 'İlk kurulumda AWS Access Key ID ve Secret Access Key zorunludur.',
        'KEY_PAIR_INCOMPLETE' => 'AWS Access Key ID ve Secret Access Key birlikte girilmelidir; ikisi de boş bırakılırsa kayıtlı anahtar korunur.',
        'KEY_PAIR_INVALID' => 'AWS anahtarı biçimi geçersiz.',
        'INVALID_FROM' => 'Gönderici adresi geçersiz.',
        'INVALID_FROM_NAME' => 'Gönderici adı geçersiz.',
        'INVALID_REPLY_TO' => 'Yanıt adresi geçersiz.',
        'IDENTITY_MISMATCH' => 'SES kimliği gönderici adresi, onun domaini veya üst domaini olmalı.',
        'UNKNOWN_SENDER' => 'Gönderici bulunamadı.',
        'CAPABILITY_MISSING' => 'Bu sağlayıcı kimlik doğrulama durumunu bildiremiyor.',
        'INVALID_RECIPIENT' => 'Geçerli tek bir e-posta adresi girin.',
        'LABEL_REQUIRED' => 'Adresin kime ait olduğunu yazın.',
        'APPROVER_REQUIRED' => 'Onaylayan kişiyi yazın.',
        'RECIPIENT_COUNT' => 'Test e-postası 1 ile 5 alıcıya gönderilebilir.',
        'CONFIRMATION_MISSING' => 'Onay ifadesini aynen yazın.',
        'SENDING_MODE_OFF' => 'Test gönderimi için gönderim modu kapalı.',
        'NOT_TEST_RECIPIENT' => 'Yalnız aktif test alıcılarına gönderilebilir.',
        'PROVIDER_NOT_CONNECTED' => 'Önce SES bağlantısını test edin.',
        'SEND_SCOPE_MISSING' => 'Entegrasyonun gönderim yetkisi (send_email) kapalı.',
        'SENDER_NOT_VERIFIED' => 'Gönderici SES\'te doğrulanmamış.',
        'NO_PENDING_SUBSCRIPTION' => 'Onay bekleyen SNS aboneliği yok.',
        'TOPIC_NOT_ALLOWED' => 'Abonelik, kayıtlı SNS topic\'lerinden birine ait değil.',
        'UNTRUSTED_SUBSCRIBE_URL' => 'Onay isteği güvenilir bir AWS SNS adresinden gelmedi; onaylanmadı.',
        'AWS_UNREACHABLE' => 'AWS SNS\'e ulaşılamadı; abonelik onaylanmadı.',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly SesIntegrations $integrations,
        private readonly SesAccountCheck $check,
        private readonly SenderIdentities $senders,
        private readonly SnsSubscriptions $sns,
        private readonly TestRecipients $testRecipients,
        private readonly TestEmails $testEmails,
        private readonly SesReadiness $readiness,
        private readonly EmailLaunchReadiness $launch,
    ) {}

    /**
     * Everything the SES screen shows: metadata only, never the key pair,
     * its ciphertext or a SubscribeURL.
     *
     * @return array<string, mixed>
     */
    public function show(string $tenantId): array
    {
        return $this->context->run($this->id($tenantId), function () {
            $i = $this->integrations->current();
            $readiness = $this->readiness->evaluate($i);

            return [
                'integration' => $i === null ? null : $this->metadata($i),
                'senders' => $i === null ? [] : DB::table('email_sender_identities')->where('integration_id', $i->id)->orderBy('from_email')
                    ->get(['id', 'from_email', 'from_name', 'reply_to_email', 'domain', 'provider_identity_id', 'verification_status', 'dkim_status', 'mail_from_domain',
                        'mail_from_status', 'spf_status', 'dmarc_status', 'dns_records', 'last_checked_at'])
                    ->map(fn (object $r) => (array) $r + ['records' => (array) json_decode((string) $r->dns_records, true)])->all(),
                'sns' => $i === null ? null : $this->sns->state($i),
                'test_recipients' => $this->testRecipients->list()->map(fn (object $r) => (array) $r)->all(),
                'test_blocker' => $this->testEmails->blocker($i),
                'last_test' => $i === null ? null : $this->testEmails->last($i),
                'unsubscribe' => $this->readiness->unsubscribe(),
                'readiness' => $readiness,
                'launch' => $this->launch->evaluate($i, $readiness),
            ];
        });
    }

    /**
     * E-posta / SES Hazırlık on the tenant page.
     *
     * @return array<string, mixed>
     */
    public function readiness(string $tenantId): array
    {
        return $this->context->run($this->id($tenantId), fn () => $this->readiness->evaluate($this->integrations->current()));
    }

    /**
     * An empty key pair keeps the stored one; a new one replaces it.
     *
     * @param  list<string>  $topicArns
     * @param  list<string>  $tracking
     */
    public function configure(string $tenantId, string $region, ?string $configurationSet, array $topicArns, array $tracking,
        #[\SensitiveParameter] ?string $accessKeyId, #[\SensitiveParameter] ?string $secretAccessKey, Actor $actor): void
    {
        $this->guarded($tenantId, fn () => $this->integrations->save($region, $configurationSet, $topicArns, $accessKeyId, $secretAccessKey, $actor, $tracking));
    }

    public function test(string $tenantId, Actor $actor): ConnectionResult
    {
        return $this->withIntegration($tenantId, function (TenantIntegration $i) use ($actor) {
            if ($i->encrypted_secret === null) {
                throw new ProvisioningError('Önce AWS kimlik bilgilerini kaydedin.');
            }

            return $this->check->run($i, $actor);
        });
    }

    public function saveSender(string $tenantId, string $fromEmail, ?string $fromName, ?string $replyTo, ?string $identity, Actor $actor): void
    {
        $this->withIntegration($tenantId, fn (TenantIntegration $i) => $this->senders->save($i, $fromEmail, $fromName, $replyTo, $identity === '' ? null : $identity, $actor));
    }

    /**
     * Doğrula / Yenile: reads the identity's state at SES and DMARC in DNS.
     *
     * @return array<string, mixed>
     */
    public function refreshSender(string $tenantId, string $senderId, Actor $actor): array
    {
        return $this->withIntegration($tenantId, function (TenantIntegration $i) use ($senderId, $actor) {
            try {
                return $this->senders->refresh($i, Str::isUuid($senderId) ? $senderId : throw new ProvisioningError(self::ERRORS['UNKNOWN_SENDER']), $actor);
            } catch (ProviderError $e) {
                throw new ProvisioningError($e->category === ProviderError::AUTH
                    ? 'SES kimlik durumunu okumaya izin vermedi (IAM: ses:GetEmailIdentity).'
                    : (str_contains($e->getMessage(), 'NotFoundException') ? 'Bu kimlik SES\'te yok: önce AWS konsolunda domain kimliğini oluşturun.' : 'SES yanıt vermedi; tekrar deneyin.'));
            }
        });
    }

    public function confirmSubscription(string $tenantId, Actor $actor): void
    {
        $this->withIntegration($tenantId, fn (TenantIntegration $i) => $this->sns->confirm($i, $actor));
    }

    /**
     * @return list<string>
     */
    public function setSendScope(string $tenantId, bool $grant, Actor $actor): array
    {
        return $this->withIntegration($tenantId, fn (TenantIntegration $i) => $this->integrations->setSendScope($i, $grant, $actor));
    }

    public function addTestRecipient(string $tenantId, string $email, string $label, string $approvedBy, Actor $actor): void
    {
        $this->guarded($tenantId, fn () => $this->testRecipients->add($email, $label, $approvedBy, $actor));
    }

    public function removeTestRecipient(string $tenantId, string $email, Actor $actor): void
    {
        $this->guarded($tenantId, fn () => $this->testRecipients->remove($email, $actor));
    }

    /**
     * @param  list<string>  $recipients
     * @return list<string> queued message ids
     */
    public function sendTest(string $tenantId, array $recipients, string $fromEmail, string $confirmation, Actor $actor): array
    {
        return $this->withIntegration($tenantId, fn (TenantIntegration $i) => $this->testEmails->send($i, $recipients, $fromEmail, $confirmation, $actor));
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(TenantIntegration $i): array
    {
        $settings = (array) $i->settings;
        $change = DB::table('audit_logs')->where('resource_type', 'tenant_integration')->where('resource_id', $i->id)
            ->whereIn('action', array_map(fn (AuditAction $a) => $a->value, self::SECRET_ACTIONS))->orderByDesc('created_at')->first(['actor_type', 'actor_id', 'created_at']);

        return [
            'status' => $i->status,
            'has_secret' => $i->encrypted_secret !== null,
            'secret_changed_at' => $change?->created_at,
            'secret_changed_by' => $change === null ? null : $this->actorLabel($change->actor_type, $change->actor_id),
            'region' => $settings['region'] ?? null,
            'configuration_set' => $settings['configuration_set'] ?? null,
            'sns_topic_arns' => array_values((array) ($settings['sns_topic_arns'] ?? [])),
            'tracking' => array_values((array) ($settings['tracking'] ?? [])),
            'account' => (array) ($settings['account'] ?? []),
            'configuration_set_check' => (array) ($settings['configuration_set_check'] ?? []),
            'scopes' => (array) $i->scopes,
            'last_verified_at' => $i->last_verified_at?->toIso8601String(),
            'last_error' => $i->last_error,
        ];
    }

    /**
     * @template T
     *
     * @param  Closure(TenantIntegration): T  $action
     * @return T
     */
    private function withIntegration(string $tenantId, Closure $action): mixed
    {
        return $this->guarded($tenantId, fn () => $action($this->integrations->current() ?? throw new ProvisioningError('Bu müşteri için Amazon SES entegrasyonu yok.')));
    }

    /**
     * Runs in the tenant's context; service errors become a ProvisioningError
     * with a Turkish message (a reason code, never input or a secret).
     *
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T
     */
    private function guarded(string $tenantId, Closure $action): mixed
    {
        try {
            return $this->context->run($this->id($tenantId), $action);
        } catch (InvalidEmailMessage|EmailSendBlocked $e) {
            throw new ProvisioningError(self::ERRORS[$e->reason] ?? "İşlem engellendi ({$e->reason}).");
        } catch (SnsConfirmationFailed $e) {
            throw new ProvisioningError(self::ERRORS[$e->reason] ?? (str_starts_with($e->reason, 'AWS_REJECTED_HTTP_')
                ? 'AWS onay isteğini reddetti (süresi dolmuş olabilir); AWS konsolundan onay isteğini yeniden gönderin.' : 'Abonelik onaylanamadı.'));
        } catch (InvalidArgumentException $e) {
            throw new ProvisioningError(self::ERRORS[$e->getMessage()] ?? 'Girilen değerler geçersiz.');
        }
    }

    private function actorLabel(string $type, ?string $id): string
    {
        return match ($type) {
            'platform_admin' => 'Platform yöneticisi'.(($name = $id === null ? null : PlatformAdmin::query()->whereKey($id)->value('name')) ? " ({$name})" : ''),
            'console' => 'Sunucu komutu'.($id ? " ({$id})" : ''),
            'user' => 'Müşteri kullanıcısı',
            default => $type,
        };
    }

    private function id(string $tenantId): string
    {
        return Str::isUuid($tenantId) ? $tenantId : throw new ProvisioningError('Müşteri bulunamadı.');
    }
}
