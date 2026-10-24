<?php

namespace App\Push;

use App\Models\IntegrationSyncRun;
use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Read-only push metrics from our own tables (no provider call). Counts,
 * statuses and masked external id formats only; no tokens, no secrets.
 */
final class PushMetrics
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PushSendGuard $guard,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(TenantIntegration $integration): array
    {
        $tenantId = $this->context->requireTenant();
        $subs = DB::table('push_subscriptions')->where('tenant_id', $tenantId)->where('integration_id', $integration->id)->whereNull('removed_at');
        $profiles = DB::table('push_profiles')->where('tenant_id', $tenantId)->where('integration_id', $integration->id);
        $lastRun = IntegrationSyncRun::query()->where('integration_id', $integration->id)->where('entity', OneSignalSync::ENTITY)->orderByDesc('started_at')->first();
        $lastSuccess = DB::table('integration_sync_cursors')->where('integration_id', $integration->id)->where('entity', OneSignalSync::ENTITY)->value('last_success_at');

        $byStatus = (clone $subs)->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $byPlatform = (clone $subs)->selectRaw('platform, count(*) AS n')->groupBy('platform')->pluck('n', 'platform')->map(fn ($n) => (int) $n)->all();
        $byPlatformSubscribed = (clone $subs)->where('status', 'subscribed')->selectRaw('platform, count(*) AS n')->groupBy('platform')->pluck('n', 'platform')->map(fn ($n) => (int) $n)->all();
        $identity = (clone $profiles)->selectRaw('identity_status, count(*) AS n')->groupBy('identity_status')->pluck('n', 'identity_status')->map(fn ($n) => (int) $n)->all();
        $profileTotal = (clone $profiles)->count();
        $withExternal = (clone $profiles)->whereNotNull('external_id')->count();
        $duplicates = (int) DB::selectOne('SELECT count(*) AS n FROM (SELECT external_id FROM push_profiles WHERE tenant_id = ? AND integration_id = ? AND external_id IS NOT NULL GROUP BY external_id HAVING count(*) > 1) d', [$tenantId, $integration->id])->n;
        $multiSubscription = (clone $profiles)->where('subscription_count', '>', 1)->count();

        return [
            'provider' => 'onesignal',
            'connected' => $integration->status === 'connected',
            'status' => $integration->status,
            'scopes' => $integration->scopes,
            'sending_mode' => $this->guard->mode()->value,
            'last_sync_status' => $lastRun?->status,
            'last_sync_started_at' => $lastRun?->started_at?->toIso8601String(),
            'last_successful_sync_at' => $lastSuccess,
            'last_error_category' => $lastRun?->error_category,
            'last_api_error' => $integration->last_error,
            'users' => $profileTotal,
            'subscriptions' => array_sum($byStatus),
            'subscribed' => $byStatus['subscribed'] ?? 0,
            'unsubscribed' => $byStatus['unsubscribed'] ?? 0,
            'never_subscribed' => $byStatus['never_subscribed'] ?? 0,
            'platforms' => $byPlatform,
            'subscribed_by_platform' => $byPlatformSubscribed,
            'external_id_coverage' => ['with' => $withExternal, 'without' => $profileTotal - $withExternal, 'percent' => $profileTotal > 0 ? round(100 * $withExternal / $profileTotal, 2) : 0.0],
            'duplicate_external_ids' => $duplicates,
            'users_with_multiple_subscriptions' => $multiSubscription,
            'identity' => $identity,
            'unresolved_identities' => ($identity['unresolved'] ?? 0) + ($identity['conflict'] ?? 0),
            'removed_at_provider' => DB::table('push_subscriptions')->where('tenant_id', $tenantId)->where('integration_id', $integration->id)->whereNotNull('removed_at')->count(),
            'test_recipients' => DB::table('test_recipients')->where('tenant_id', $tenantId)->where('channel', 'push')->where('active', true)->count(),
            'last_sync' => $integration->settings['last_sync'] ?? null,
        ];
    }

    /**
     * Shapes of external ids (never the ids): numeric, e-mail-like, uuid,
     * our platform prefix, other; with masked examples.
     *
     * @return array<string, array{count: int, examples: list<string>}>
     */
    public function externalIdFormats(TenantIntegration $integration): array
    {
        $rows = DB::select(
            "SELECT CASE
                WHEN external_id ~ '^[0-9]+$' THEN 'numeric'
                WHEN external_id ~ '^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$' THEN 'email'
                WHEN external_id ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN 'uuid'
                WHEN external_id LIKE 'ptc\\_%' THEN 'platform'
                ELSE 'other' END AS format,
                count(*) AS n,
                (array_agg(left(external_id, 2) || repeat('*', greatest(length(external_id) - 4, 1)) || right(external_id, 2) ORDER BY id))[1:3] AS examples,
                min(length(external_id)) AS min_len, max(length(external_id)) AS max_len
             FROM push_profiles WHERE tenant_id = ? AND integration_id = ? AND external_id IS NOT NULL GROUP BY 1 ORDER BY 2 DESC",
            [$this->context->requireTenant(), $integration->id],
        );

        $out = [];
        foreach ($rows as $r) {
            $out[$r->format] = ['count' => (int) $r->n, 'length' => "{$r->min_len}-{$r->max_len}", 'examples' => array_values(array_filter(explode(',', trim((string) $r->examples, '{}'))))];
        }

        return $out;
    }

    /**
     * Health without a provider call: configured, last sync, last error,
     * sending mode. A provider problem is a warning, never a failure.
     *
     * @return array<string, mixed>
     */
    public function health(?TenantIntegration $integration): array
    {
        if ($integration === null) {
            return ['status' => 'not_configured'];
        }

        $lastSuccess = DB::table('integration_sync_cursors')->where('integration_id', $integration->id)->where('entity', OneSignalSync::ENTITY)->value('last_success_at');
        $lastRun = IntegrationSyncRun::query()->where('integration_id', $integration->id)->where('entity', OneSignalSync::ENTITY)->orderByDesc('started_at')->first();
        $warn = $integration->status !== 'connected' || $lastRun?->status === 'failed';

        return [
            'status' => $warn ? 'warn' : 'ok',
            'configured' => true,
            'integration_status' => $integration->status,
            'last_sync' => $lastRun?->started_at?->toIso8601String(),
            'last_success' => $lastSuccess,
            'last_error_code' => $lastRun?->error_category,
            'sending_mode' => $this->guard->mode()->value,
        ];
    }
}
