<?php

namespace App\Email;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Email\Data\EmailDraft;
use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * One harmless test e-mail per allowlisted recipient (email:test and the
 * Super Admin "Test e-postası gönder" button). Refused before anything is
 * queued unless the confirmation phrase is given, the effective sending
 * mode is not off, the integration is connected and holds send_email, the
 * sender is verified and every recipient is an active test recipient. The
 * send job and EmailSendGuard check all of it again before any request.
 */
final class TestEmails
{
    public const CONFIRMATION = 'SEND REAL TEST EMAIL';

    public const SUBJECT = 'PratikTools e-posta bağlantı testi';

    public function __construct(
        private readonly TenantContext $context,
        private readonly EmailDelivery $delivery,
        private readonly EmailSendGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Why a test e-mail cannot be queued right now, or null. The first
     * blocker wins; the order is the order an operator fixes them in.
     *
     * @return ?array{code: string, message: string}
     */
    public function blocker(?TenantIntegration $integration): ?array
    {
        $this->context->requireTenant();

        return match (true) {
            EmailSendingMode::platform() === EmailSendingMode::Off => ['code' => 'SENDING_MODE_OFF', 'message' => 'Test gönderimi için platform gönderim modu kapalı.'],
            $this->guard->mode() === EmailSendingMode::Off => ['code' => 'TENANT_SENDING_MODE_OFF', 'message' => 'Test gönderimi için müşterinin gönderim modu kapalı.'],
            $integration === null || $integration->status !== 'connected' => ['code' => 'PROVIDER_NOT_CONNECTED', 'message' => 'Önce SES bağlantısını test edin.'],
            ! in_array(EmailSendGuard::SCOPE, (array) $integration->scopes, true) => ['code' => 'SEND_SCOPE_MISSING', 'message' => 'Entegrasyonun gönderim yetkisi (send_email) kapalı.'],
            ! DB::table('email_sender_identities')->where('integration_id', $integration->id)->where('verification_status', 'verified')->exists() => ['code' => 'SENDER_NOT_VERIFIED', 'message' => 'Doğrulanmış gönderici yok.'],
            ! DB::table('test_recipients')->where('channel', 'email')->where('provider', '*')->where('active', true)->exists() => ['code' => 'NO_TEST_RECIPIENT', 'message' => 'Aktif test alıcısı yok.'],
            default => null,
        };
    }

    /**
     * @param  list<string>  $recipients
     * @return list<string> the queued message ids
     *
     * @throws EmailSendBlocked|InvalidEmailMessage nothing was queued
     */
    public function send(TenantIntegration $integration, array $recipients, string $fromEmail, string $confirmation, Actor $actor): array
    {
        if ($confirmation !== self::CONFIRMATION) {
            throw new EmailSendBlocked('CONFIRMATION_MISSING', 'This sends a real e-mail; the confirmation phrase is required.');
        }
        $recipients = array_values(array_unique(array_map(fn (string $r) => MessageRules::address($r, 'INVALID_RECIPIENT'), $recipients)));
        if ($recipients === [] || count($recipients) > (int) config('email.max_test_recipients')) {
            throw new InvalidEmailMessage('RECIPIENT_COUNT', 'Between 1 and '.config('email.max_test_recipients').' recipients.');
        }
        if ($this->guard->mode() === EmailSendingMode::Off) {
            throw new EmailSendBlocked('SENDING_MODE_OFF', 'E-mail sending is off.');
        }
        foreach ($recipients as $r) {
            if (! $this->guard->isTestRecipient($r)) {
                throw new EmailSendBlocked('NOT_TEST_RECIPIENT', 'Only active test recipients may receive a test e-mail.');
            }
        }
        if ($integration->status !== 'connected') {
            throw new EmailSendBlocked('PROVIDER_NOT_CONNECTED', 'The e-mail integration is not connected.');
        }
        if (! in_array(EmailSendGuard::SCOPE, (array) $integration->scopes, true)) {
            throw new EmailSendBlocked('SEND_SCOPE_MISSING', 'The integration does not have the send_email scope.');
        }
        $identity = DB::table('email_sender_identities')->where('tenant_id', $this->context->requireTenant())->where('integration_id', $integration->id)
            ->where('from_email', strtolower(trim($fromEmail)))->first();
        if ($identity === null || $identity->verification_status !== 'verified') {
            throw new EmailSendBlocked('SENDER_NOT_VERIFIED', 'The sender identity is not verified.');
        }

        $ids = [];
        foreach ($recipients as $to) {
            $ids[] = $this->delivery->queue(new EmailDraft($integration->id, $identity->id, EmailMessageType::Test, $to, self::SUBJECT,
                "Bu, PratikTools e-posta altyapısının test mesajıdır.\nBir işlem yapmanız gerekmez.", tags: ['source' => 'pratiktools_test']), $actor);
        }
        $this->audit->record(AuditAction::EmailTestRequested, 'tenant_integration', $integration->id, ['recipients' => count($ids), 'sender_identity_id' => $identity->id], $actor);

        return $ids;
    }

    /**
     * The latest test e-mail of the integration: status only.
     *
     * @return ?array{status: string, delivery_status: ?string, block_reason: ?string, created_at: string}
     */
    public function last(TenantIntegration $integration): ?array
    {
        $row = DB::table('email_messages')->where('tenant_id', $this->context->requireTenant())->where('integration_id', $integration->id)
            ->where('message_type', EmailMessageType::Test->value)->orderByDesc('created_at')->first(['status', 'delivery_status', 'block_reason', 'created_at']);

        return $row === null ? null : (array) $row;
    }
}
