<?php

namespace App\Push;

use App\Models\TenantIntegration;
use App\Models\TenantSetting;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether a push may be sent. Everything is checked before a
 * request is built:
 * - effective sending mode (platform kill switch and tenant mode) not off;
 * - the integration is connected and holds the send_push scope;
 * - test_only: every recipient is an active test recipient, at most
 *   push.max_test_recipients;
 * - live: not available in F3 (refused until audience sending exists);
 * - every recipient is a known subscription of this tenant that is
 *   subscribed and not suppressed.
 */
final class PushSendGuard
{
    public const SCOPE = 'send_push';

    public function __construct(
        private readonly TenantContext $context,
        private readonly PushEligibility $eligibility,
    ) {}

    public function mode(): PushSendingMode
    {
        $tenantMode = TenantSetting::query()->value('sending_mode');

        return PushSendingMode::forTenant(is_string($tenantMode) ? $tenantMode : null);
    }

    /**
     * @param  list<string>  $subscriptionIds  provider subscription ids
     */
    public function authorize(TenantIntegration $integration, array $subscriptionIds, string $idempotencyKey): SendAuthorization
    {
        $tenantId = $this->context->requireTenant();
        $mode = $this->mode();
        $subscriptionIds = array_values(array_unique($subscriptionIds));

        if ($mode === PushSendingMode::Off) {
            throw new PushSendBlocked('SENDING_MODE_OFF', 'Push sending is off (PUSH_SENDING_MODE / tenant sending_mode).');
        }
        if ($mode === PushSendingMode::Live) {
            throw new PushSendBlocked('LIVE_NOT_AVAILABLE', 'Live push sending is not available in this phase.');
        }
        if ($integration->tenant_id !== $tenantId || $integration->provider !== 'onesignal' || $integration->status !== 'connected') {
            throw new PushSendBlocked('PROVIDER_NOT_CONNECTED', 'The OneSignal integration is not connected for this tenant.');
        }
        if (! in_array(self::SCOPE, (array) $integration->scopes, true)) {
            throw new PushSendBlocked('SEND_SCOPE_MISSING', 'The integration does not have the send_push scope.');
        }
        if ($subscriptionIds === [] || count($subscriptionIds) > (int) config('push.max_test_recipients')) {
            throw new PushSendBlocked('RECIPIENT_COUNT', 'A test push needs between 1 and '.config('push.max_test_recipients').' recipients.');
        }

        $allowlisted = DB::table('test_recipients')->where('tenant_id', $tenantId)->where('channel', 'push')->where('provider', 'onesignal')
            ->where('active', true)->whereIn('provider_recipient_id', $subscriptionIds)->pluck('provider_recipient_id')->all();
        if (count($allowlisted) !== count($subscriptionIds)) {
            throw new PushSendBlocked('NOT_TEST_RECIPIENT', 'test_only mode: every recipient must be an active test recipient.');
        }

        foreach ($subscriptionIds as $id) {
            $reason = $this->eligibility->forProviderSubscription('onesignal', $id);
            if ($reason !== PushEligibility::ALLOWED) {
                throw new PushSendBlocked($reason, "Recipient is not eligible for push ({$reason}).");
            }
        }

        $integrationId = $integration->id;

        return \Closure::bind(
            fn () => new SendAuthorization($tenantId, $integrationId, $mode, $subscriptionIds, $idempotencyKey),
            null,
            SendAuthorization::class,
        )();
    }
}
