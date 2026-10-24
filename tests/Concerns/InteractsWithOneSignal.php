<?php

namespace Tests\Concerns;

use App\Connectors\OneSignal\OneSignalClientFactory;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fake OneSignal shaped like the current API: view app, CSV export (the
 * file 404s until "generated"), create notification. Records requests.
 */
trait InteractsWithOneSignal
{
    protected const APP_ID = '11111111-2222-4333-8444-555555555555';

    protected const API_KEY = 'os_v2_app_FAKE-TEST-KEY-never-real-0123456789';

    /** @var list<array<string, string|int|null>> rows of the export (header names as keys) */
    protected array $osRows = [];

    protected int $osNotReadyPolls = 1;

    /** @var list<Request> */
    protected array $osRequests = [];

    /** @var list<int> */
    protected array $osSleeps = [];

    protected ?Closure $osOverride = null;

    protected function setUpOneSignal(): void
    {
        config([
            'push.onesignal.base_backoff_ms' => 10,
            'push.onesignal.export_poll_seconds' => 1,
            'platform.rate_limits.providers.onesignal_view' => ['tenant' => ['capacity' => 10000, 'refill_per_second' => 10000]],
            'platform.rate_limits.providers.onesignal_send' => ['tenant' => ['capacity' => 10000, 'refill_per_second' => 10000]],
        ]);
        $this->app->instance('onesignal.sleep', function (int $ms) {
            $this->osSleeps[] = $ms;
        });
        $this->app->forgetInstance(OneSignalClientFactory::class);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $this->osRequests[] = $request;
            if ($this->osOverride !== null && ($r = ($this->osOverride)($request)) !== null) {
                return $r;
            }
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_starts_with($path, '/csv_exports/')) {
                if ($this->osNotReadyPolls-- > 0) {
                    return Http::response('<Error><Code>NoSuchKey</Code></Error>', 404);
                }

                return Http::response(gzencode($this->csv()), 200);
            }
            if (($request->header('Authorization')[0] ?? '') !== 'Key '.self::API_KEY) {
                return Http::response(['errors' => ['Access denied. Please include an Authorization header with a valid API key.']], 401);
            }

            return match (true) {
                $path === '/apps/'.self::APP_ID => Http::response(['id' => self::APP_ID, 'name' => 'IGS', 'players' => count($this->osRows), 'messageable_players' => count(array_filter($this->osRows, fn ($r) => (int) ($r['notification_types'] ?? 1) >= 1))]),
                $path === '/players/csv_export' => Http::response(['csv_file_url' => 'https://onesignal.com/csv_exports/abc/users_export.csv.gz']),
                $path === '/notifications' => Http::response(['id' => 'b98881cc-1e94-4366-bbd9-db8f3429292b', 'external_id' => $request->data()['idempotency_key'] ?? null]),
                default => Http::response(['errors' => ['not faked']], 404),
            };
        });
    }

    protected function csv(): string
    {
        $header = ['id', 'identifier', 'session_count', 'language', 'timezone', 'game_version', 'device_os', 'device_type', 'device_model', 'ad_id', 'tags',
            'last_active', 'playtime', 'amount_spent', 'created_at', 'invalid_identifier', 'external_user_id', 'onesignal_id', 'unsubscribed_at', 'notification_types', 'country'];
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $header, escape: '');
        foreach ($this->osRows as $row) {
            fputcsv($out, array_map(fn ($h) => $row[$h] ?? '', $header), escape: '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * @param  array<string, string|int|null>  $overrides
     * @return array<string, string|int|null>
     */
    protected static function osRow(string $subscriptionId, ?string $onesignalId, ?string $externalId, int $notificationTypes = 1, int $deviceType = 1, array $overrides = []): array
    {
        return $overrides + [
            'id' => $subscriptionId, 'identifier' => 'PUSH-TOKEN-'.$subscriptionId, 'session_count' => 12, 'language' => 'tr', 'game_version' => '5.2.1',
            'device_os' => '14', 'device_type' => $deviceType, 'device_model' => 'SM-G991B', 'tags' => '{"segment":"vip","last_cart":"x"}',
            'last_active' => '2026-10-20 10:00:00', 'created_at' => '2025-01-05 09:00:00', 'invalid_identifier' => $notificationTypes >= 1 ? 'f' : 't',
            'external_user_id' => $externalId, 'onesignal_id' => $onesignalId, 'unsubscribed_at' => $notificationTypes < 0 ? '2026-09-01 08:00:00' : '',
            'notification_types' => $notificationTypes, 'country' => 'TR',
        ];
    }

    protected function connectOneSignal(Tenant $tenant, string $status = 'connected', array $scopes = ['read_users', 'read_subscriptions']): TenantIntegration
    {
        $integration = $this->createIntegration($tenant, self::API_KEY, 'onesignal', 'push');

        return $this->inTenant($tenant, function () use ($integration, $status, $scopes) {
            $integration->forceFill(['settings' => ['app_id' => self::APP_ID], 'status' => $status, 'scopes' => $scopes])->save();

            return $integration;
        });
    }

    /**
     * @return list<Request>
     */
    protected function osRequestsTo(string $path): array
    {
        return array_values(array_filter($this->osRequests, fn (Request $r) => (string) parse_url($r->url(), PHP_URL_PATH) === $path));
    }
}
