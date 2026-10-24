<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Console\Commands\Concerns\FindsEmailIntegration;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Email\Data\EmailDraft;
use App\Email\EmailDelivery;
use App\Email\EmailMessageType;
use App\Email\EmailSendGuard;
use App\Email\EmailSendingMode;
use App\Email\InvalidEmailMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Queues one harmless test e-mail per allowlisted recipient. Refuses
 * before queueing unless EMAIL_SENDING_MODE / tenant mode is test_only
 * (or live), every recipient is an active test recipient, the sender is
 * verified and the confirmation phrase is given; the send job checks all
 * of it again before any network request.
 */
final class EmailTestSend extends Command
{
    use FindsEmailIntegration, RunsInTenantContext;

    public const CONFIRMATION = 'SEND REAL TEST EMAIL';

    protected $signature = 'email:test {--tenant= : Tenant slug} {--recipient=* : Address(es) on the e-mail test allowlist} {--from= : Verified sender address}
        {--provider= : amazon_ses (default: primary)} {--confirm= : Must be "SEND REAL TEST EMAIL"}';

    protected $description = 'Send one harmless test e-mail to allowlisted test recipients (real delivery)';

    public function handle(EmailDelivery $delivery, EmailSendGuard $guard): int
    {
        if ($this->option('confirm') !== self::CONFIRMATION) {
            $this->error('This sends a real e-mail. Re-run with --confirm="'.self::CONFIRMATION.'".');

            return self::INVALID;
        }
        $recipients = array_values(array_unique(array_map('strtolower', (array) $this->option('recipient'))));
        if ($recipients === [] || count($recipients) > (int) config('email.max_test_recipients')) {
            $this->error('Pass between 1 and '.config('email.max_test_recipients').' --recipient addresses.');

            return self::INVALID;
        }

        return $this->inTenant(function () use ($delivery, $guard, $recipients) {
            if ($guard->mode() === EmailSendingMode::Off) {
                $this->error('Blocked before queueing: SENDING_MODE_OFF.');

                return self::FAILURE;
            }
            foreach ($recipients as $r) {
                if (! $guard->isTestRecipient($r)) {
                    $this->error('Blocked before queueing: NOT_TEST_RECIPIENT.');

                    return self::FAILURE;
                }
            }

            $integration = $this->emailIntegration($this->option('provider'));
            $identity = DB::table('email_sender_identities')->where('integration_id', $integration->id)->where('from_email', strtolower((string) $this->option('from')))->first();
            if ($identity === null || $identity->verification_status !== 'verified') {
                $this->error('Blocked before queueing: SENDER_NOT_VERIFIED.');

                return self::FAILURE;
            }

            foreach ($recipients as $to) {
                try {
                    $id = $delivery->queue(new EmailDraft($integration->id, $identity->id, EmailMessageType::Test, $to, 'PratikTools e-posta bağlantı testi',
                        "Bu, PratikTools e-posta altyapısının test mesajıdır.\nBir işlem yapmanız gerekmez.", tags: ['source' => 'pratiktools_test']), Actor::console($this->getName()));
                } catch (InvalidEmailMessage $e) {
                    $this->error("Blocked before queueing: {$e->reason}.");

                    return self::FAILURE;
                }
                $this->info("Test e-mail queued (message {$id}) on the send queue.");
            }

            return self::SUCCESS;
        });
    }
}
