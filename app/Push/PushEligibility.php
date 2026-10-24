<?php

namespace App\Push;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Push eligibility, a channel of its own: decided only by the push
 * subscription and push suppressions. It never reads or writes e-mail/SMS
 * consent (consents / İYS), and those never decide push.
 */
final class PushEligibility
{
    public const ALLOWED = 'ALLOWED';

    public const UNKNOWN_SUBSCRIPTION = 'UNKNOWN_SUBSCRIPTION';

    public const NEVER_SUBSCRIBED = 'NEVER_SUBSCRIBED';

    public const UNSUBSCRIBED = 'UNSUBSCRIBED';

    public const REMOVED = 'REMOVED_AT_PROVIDER';

    public const SUPPRESSED = 'PUSH_SUPPRESSED';

    public function __construct(private readonly TenantContext $context) {}

    public function forProviderSubscription(string $provider, string $providerSubscriptionId): string
    {
        $row = DB::selectOne(
            'SELECT s.status, s.removed_at,
                    EXISTS (SELECT 1 FROM push_suppressions ps WHERE ps.tenant_id = s.tenant_id AND ps.push_profile_id = s.push_profile_id AND ps.lifted_at IS NULL) AS suppressed
             FROM push_subscriptions s
             WHERE s.tenant_id = ? AND s.provider = ? AND s.provider_subscription_id = ?',
            [$this->context->requireTenant(), $provider, $providerSubscriptionId],
        );

        return match (true) {
            $row === null => self::UNKNOWN_SUBSCRIPTION,
            $row->removed_at !== null => self::REMOVED,
            $row->status === 'never_subscribed' => self::NEVER_SUBSCRIBED,
            $row->status !== 'subscribed' => self::UNSUBSCRIBED,
            (bool) $row->suppressed => self::SUPPRESSED,
            default => self::ALLOWED,
        };
    }
}
