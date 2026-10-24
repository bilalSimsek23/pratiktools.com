<?php

namespace App\Push;

use App\Tenancy\TenantContext;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a push audience to provider subscription ids, always applying
 * push eligibility (subscribed, present at the provider, not suppressed).
 */
final class PushAudienceResolver
{
    public function __construct(private readonly TenantContext $context) {}

    public function count(PushAudienceCriteria $criteria): int
    {
        return $this->query($criteria)->count();
    }

    /**
     * @return Generator<int, list<string>> chunks of provider subscription ids
     */
    public function chunks(PushAudienceCriteria $criteria, int $size = 2000): Generator
    {
        $last = '';
        while (true) {
            $ids = $this->query($criteria)->where('s.provider_subscription_id', '>', $last)->orderBy('s.provider_subscription_id')
                ->limit($size)->pluck('s.provider_subscription_id')->all();
            if ($ids === []) {
                return;
            }
            yield $ids;
            $last = (string) end($ids);
        }
    }

    private function query(PushAudienceCriteria $c): Builder
    {
        $tenantId = $this->context->requireTenant();

        return DB::table('push_subscriptions as s')
            ->leftJoin('push_profiles as p', fn ($j) => $j->on('p.id', '=', 's.push_profile_id')->on('p.tenant_id', '=', 's.tenant_id'))
            ->where('s.tenant_id', $tenantId)
            ->where('s.status', 'subscribed')
            ->whereNull('s.removed_at')
            ->whereNotExists(fn ($q) => $q->from('push_suppressions as ps')->whereColumn('ps.tenant_id', 's.tenant_id')
                ->whereColumn('ps.push_profile_id', 's.push_profile_id')->whereNull('ps.lifted_at'))
            ->when($c->platforms !== null, fn ($q) => $q->whereIn('s.platform', $c->platforms))
            ->when($c->activeWithinDays !== null, fn ($q) => $q->where('s.last_session_at', '>=', now()->subDays((int) $c->activeWithinDays)))
            ->when($c->customerIds !== null, fn ($q) => $q->whereIn('p.customer_id', $c->customerIds))
            ->when($c->requireResolvedIdentity, fn ($q) => $q->where('p.identity_status', 'resolved'))
            ->when($c->testRecipientsOnly, fn ($q) => $q->whereExists(fn ($t) => $t->from('test_recipients as tr')->whereColumn('tr.tenant_id', 's.tenant_id')
                ->where('tr.channel', 'push')->where('tr.provider', 'onesignal')->where('tr.active', true)->whereColumn('tr.provider_recipient_id', 's.provider_subscription_id')));
    }
}
