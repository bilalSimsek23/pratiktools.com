<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Console\Commands\Concerns\FindsOneSignalIntegration;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Push\PushDelivery;
use App\Push\PushMessage;
use App\Push\PushSendBlocked;
use Illuminate\Console\Command;

/**
 * Queues one harmless test push to allowlisted test recipients. Requires
 * PUSH_SENDING_MODE / tenant sending_mode = test_only, the send_push scope,
 * allowlisted recipients, and an explicit confirmation phrase.
 */
final class OneSignalTestPush extends Command
{
    use FindsOneSignalIntegration, RunsInTenantContext;

    public const CONFIRMATION = 'SEND REAL TEST PUSH';

    protected $signature = 'onesignal:test-push {--tenant= : Tenant slug} {--recipient=* : OneSignal subscription id(s) on the test allowlist} {--confirm= : Must be "SEND REAL TEST PUSH"}';

    protected $description = 'Send one harmless test push to allowlisted test recipients (real delivery)';

    public function handle(PushDelivery $delivery): int
    {
        if ($this->option('confirm') !== self::CONFIRMATION) {
            $this->error('This sends a real push. Re-run with --confirm="'.self::CONFIRMATION.'".');

            return self::INVALID;
        }

        return $this->inTenant(function () use ($delivery) {
            try {
                $id = $delivery->requestTestPush(
                    $this->oneSignalIntegration(),
                    array_values(array_map('strtolower', (array) $this->option('recipient'))),
                    new PushMessage('PratikTools', 'PratikTools OneSignal bağlantı testi', metadata: ['source' => 'pratiktools_test']),
                    Actor::console($this->getName()),
                );
            } catch (PushSendBlocked $e) {
                $this->error("Blocked before sending: {$e->reason}. {$e->getMessage()}");

                return self::FAILURE;
            }

            $this->info("Test push queued (request {$id}) on the send queue.");

            return self::SUCCESS;
        });
    }
}
