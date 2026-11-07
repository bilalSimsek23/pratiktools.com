<?php

namespace App\Email;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The platform e-mail switch managed in Super Admin › Sistem Durumu.
 *
 * Source of truth at runtime: platform_settings "email.sending_mode", read
 * on every check (no cache, so turning it off takes effect for the next
 * message on every worker). EMAIL_SENDING_MODE is the upper bound: the
 * effective platform mode is the stricter of the two, and the switch can
 * never be set above the bound. A switch that cannot be read counts as off.
 *
 * Moving down (to test_only or off) is always allowed. Live needs the
 * caller to have confirmed it strongly (password, second factor and the
 * typed phrase are checked by the Super Admin controller); without that
 * confirmation it is refused and the attempt is audited.
 */
final class PlatformEmailMode
{
    public const KEY = 'email.sending_mode';

    public const LIVE_PHRASE = 'CANLI GÖNDERİMİ AÇ';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The Super Admin switch as stored; off when it cannot be read.
     */
    public function stored(): EmailSendingMode
    {
        try {
            return EmailSendingMode::tryFrom((string) DB::table('platform_settings')->where('key', self::KEY)->value('value')) ?? EmailSendingMode::Off;
        } catch (Throwable) {
            return EmailSendingMode::Off;
        }
    }

    public function effective(): EmailSendingMode
    {
        return EmailSendingMode::stricter(EmailSendingMode::ceiling(), $this->stored());
    }

    /**
     * @return array{ceiling: EmailSendingMode, stored: EmailSendingMode, effective: EmailSendingMode, updated_at: ?string, updated_by: ?string}
     */
    public function state(): array
    {
        $row = null;
        try {
            $row = DB::connection('pgsql_platform')->table('platform_settings')->where('key', self::KEY)->first(['updated_at', 'updated_by']);
        } catch (Throwable) {
        }

        return ['ceiling' => EmailSendingMode::ceiling(), 'stored' => $this->stored(), 'effective' => $this->effective(),
            'updated_at' => $row?->updated_at, 'updated_by' => $row?->updated_by];
    }

    /**
     * @throws InvalidArgumentException ABOVE_CEILING, LIVE_NOT_CONFIRMED, UNCHANGED
     */
    public function set(EmailSendingMode $to, Actor $actor, bool $liveConfirmed = false): void
    {
        $from = $this->stored();
        $ceiling = EmailSendingMode::ceiling();
        $refuse = match (true) {
            $to === $from => 'UNCHANGED',
            $to->rank() > $ceiling->rank() => 'ABOVE_CEILING',
            $to === EmailSendingMode::Live && ! $liveConfirmed => 'LIVE_NOT_CONFIRMED',
            default => null,
        };
        if ($refuse !== null) {
            if ($refuse !== 'UNCHANGED') {
                $this->audit->record(AuditAction::PlatformEmailSendingModeChangeRefused, 'platform_setting', null,
                    ['from' => $from->value, 'requested' => $to->value, 'ceiling' => $ceiling->value, 'reason' => $refuse], $actor, tenantId: false, connection: 'pgsql_platform');
            }

            throw new InvalidArgumentException($refuse);
        }

        $before = $this->effective();
        DB::connection('pgsql_platform')->table('platform_settings')->where('key', self::KEY)
            ->update(['value' => $to->value, 'updated_by' => $actor->type === 'platform_admin' ? $actor->id : null, 'updated_at' => now()]);
        $this->audit->record(AuditAction::PlatformEmailSendingModeChanged, 'platform_setting', null, [
            'from' => $from->value, 'to' => $to->value, 'ceiling' => $ceiling->value, 'effective_before' => $before->value, 'effective_after' => $this->effective()->value,
        ], $actor, tenantId: false, connection: 'pgsql_platform');
    }

    /**
     * Audits a live request whose strong confirmation failed (which check,
     * never the input).
     */
    public function refuseLive(string $reason, Actor $actor): void
    {
        $this->audit->record(AuditAction::PlatformEmailSendingModeChangeRefused, 'platform_setting', null,
            ['from' => $this->stored()->value, 'requested' => EmailSendingMode::Live->value, 'ceiling' => EmailSendingMode::ceiling()->value, 'reason' => $reason],
            $actor, tenantId: false, connection: 'pgsql_platform');
    }

    /**
     * Why a tenant can or cannot send, in plain Turkish.
     */
    public static function explain(EmailSendingMode $ceiling, EmailSendingMode $stored, ?string $tenantMode): string
    {
        $tenant = EmailSendingMode::fromTenant($tenantMode);

        return match (true) {
            $ceiling === EmailSendingMode::Off => 'Engelli: ortam üst sınırı (EMAIL_SENDING_MODE) kapalı; panelden açılamaz.',
            $stored === EmailSendingMode::Off => 'Engelli: platform gönderim modu kapalı.',
            $tenant === EmailSendingMode::Off => 'Engelli: müşterinin gönderim modu kapalı.',
            EmailSendingMode::stricter(EmailSendingMode::stricter($ceiling, $stored), $tenant) === EmailSendingMode::TestOnly => 'Yalnızca test: e-posta yalnız aktif test alıcılarına gidebilir; send_email yetkisi ve diğer bütün kurallar ayrıca geçerli.',
            default => 'Canlı: send_email yetkisi, İYS onayı, suppression ve diğer bütün kurallar ayrıca geçerli.',
        };
    }
}
