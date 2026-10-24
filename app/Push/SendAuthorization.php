<?php

namespace App\Push;

/**
 * Proof that PushSendGuard approved exactly these recipients for this
 * tenant, in this mode, for this idempotency key. The constructor is
 * private; only the guard creates one (scope-bound closure). The push
 * client refuses to send without it and re-checks the recipients.
 */
final class SendAuthorization
{
    /**
     * @param  list<string>  $subscriptionIds
     */
    private function __construct(
        public readonly string $tenantId,
        public readonly string $integrationId,
        public readonly PushSendingMode $mode,
        public readonly array $subscriptionIds,
        public readonly string $idempotencyKey,
    ) {}
}
