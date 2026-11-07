<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Console\Commands\Concerns\FindsEmailIntegration;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Email\EmailSendBlocked;
use App\Email\InvalidEmailMessage;
use App\Email\TestEmails;
use Illuminate\Console\Command;

/**
 * Queues one harmless test e-mail per allowlisted recipient through
 * TestEmails (the same service as the Super Admin button). Refuses before
 * queueing unless EMAIL_SENDING_MODE / tenant mode is test_only (or live),
 * every recipient is an active test recipient, the sender is verified and
 * the confirmation phrase is given; the send job checks all of it again
 * before any network request.
 */
final class EmailTestSend extends Command
{
    use FindsEmailIntegration, RunsInTenantContext;

    public const CONFIRMATION = TestEmails::CONFIRMATION;

    protected $signature = 'email:test {--tenant= : Tenant slug} {--recipient=* : Address(es) on the e-mail test allowlist} {--from= : Verified sender address}
        {--provider= : amazon_ses (default: primary)} {--confirm= : Must be "SEND REAL TEST EMAIL"}';

    protected $description = 'Send one harmless test e-mail to allowlisted test recipients (real delivery)';

    public function handle(TestEmails $tests): int
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

        return $this->inTenant(function () use ($tests, $recipients) {
            try {
                $ids = $tests->send($this->emailIntegration($this->option('provider')), $recipients, (string) $this->option('from'), self::CONFIRMATION, Actor::console($this->getName()));
            } catch (EmailSendBlocked|InvalidEmailMessage $e) {
                $this->error("Blocked before queueing: {$e->reason}.");

                return self::FAILURE;
            }
            foreach ($ids as $id) {
                $this->info("Test e-mail queued (message {$id}) on the send queue.");
            }

            return self::SUCCESS;
        });
    }
}
