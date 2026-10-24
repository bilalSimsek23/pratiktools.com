<?php

namespace App\Push;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Connectors\OneSignal\OneSignalClientFactory;
use App\Consent\Sync\SyncTracker;
use App\Integrations\Errors\ProviderError;
use App\Models\IntegrationSyncRun;
use App\Models\TenantIntegration;
use App\Tenancy\Exceptions\TenantMismatch;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Read-only OneSignal sync: one full CSV export of the app's subscriptions
 * per run (OneSignal offers no reliable delta for status changes; an
 * export runs at ~2,000 records/s).
 *
 * - Resumable in slices: the export URL is cached encrypted (it needs no
 *   authentication), the file is streamed to a temporary path and rows
 *   are processed in chunks, with the row offset saved after each chunk.
 *   Re-processing a chunk is harmless (idempotent upserts).
 * - Only push device types are stored. The identifier column (push token,
 *   e-mail address or phone number) is never stored; e-mail and SMS
 *   subscriptions are skipped entirely.
 * - After the last chunk, subscriptions missing from the export are marked
 *   removed, and push identities are resolved.
 */
final class OneSignalSync
{
    public const ENTITY = 'onesignal_subscriptions';

    private const CHUNK = 500;

    /** @var array{rows: int, keys: array<string, int>} */
    private array $tagStats = ['rows' => 0, 'keys' => []];

    public function __construct(
        private readonly TenantContext $context,
        private readonly SyncTracker $tracker,
        private readonly OneSignalClientFactory $clients,
        private readonly PushIdentityResolver $identities,
        private readonly AuditLogger $audit,
    ) {}

    public function run(TenantIntegration $integration, ?float $sliceSeconds = null, ?Actor $actor = null): IntegrationSyncRun
    {
        // Fail closed without a tenant context, before any provider call.
        if ($integration->tenant_id !== $this->context->requireTenant()) {
            throw new TenantMismatch;
        }
        $actor ??= Actor::job(self::class);
        $run = $this->tracker->open($integration, self::ENTITY, 'full');
        $started = microtime(true);
        if ($run->page_token === null) {
            $this->audit->record(AuditAction::IntegrationSyncStarted, 'tenant_integration', $integration->id, ['provider' => 'onesignal', 'run_id' => $run->id], $actor);
        }

        try {
            $client = $this->clients->read($integration, 'push_sync', $actor);
            $urlKey = "onesignal:export:{$run->id}";
            $file = storage_path("app/private/onesignal-{$run->id}.csv.gz");

            if (! Cache::has($urlKey)) {
                Cache::put($urlKey, Crypt::encryptString($client->requestSubscriptionExport()), now()->addDays(2));
                $this->tracker->progress($run, ['api_calls' => 1], 'export_requested');
            }

            if (! is_file($file)) {
                @mkdir(dirname($file), 0700, true);
                try {
                    $polls = $client->downloadExport(Crypt::decryptString((string) Cache::get($urlKey)), $file, (int) config('push.onesignal.export_poll_seconds'), (int) config('push.onesignal.export_max_wait_seconds'));
                } catch (ProviderError $e) {
                    @unlink($file);
                    $stale = CarbonImmutable::parse($run->started_at)->lt(now()->subHour());
                    if ($e->operation === 'csv_download' && $sliceSeconds !== null && ! $stale) {
                        // Not ready yet: continue in the next slice.
                        $run->forceFill(['slices' => $run->slices + 1])->save();

                        return $run;
                    }
                    throw $e;
                }
                $this->tracker->progress($run, ['api_calls' => $polls], 'rows:0');
            }

            $offset = str_starts_with((string) $run->page_token, 'rows:') ? (int) substr((string) $run->page_token, 5) : 0;
            $done = $this->processFile($integration, $run, $file, $offset, $sliceSeconds, $started);
            if (! $done) {
                $run->forceFill(['slices' => $run->slices + 1])->save();

                return $run;
            }

            $removed = DB::table('push_subscriptions')->where('tenant_id', $integration->tenant_id)->where('integration_id', $integration->id)
                ->where(fn ($q) => $q->whereNull('last_seen_run_id')->orWhere('last_seen_run_id', '<>', $run->id))->whereNull('removed_at')
                ->update(['removed_at' => now(), 'updated_at' => now()]);
            $this->refreshProfileCounts($integration);
            $identity = $this->identities->resolveAll($integration, $actor);

            @unlink($file);
            Cache::forget($urlKey);
            $this->storeStats($integration, $run, $removed, $identity);

            $run = $this->tracker->succeed($run, $integration, full: true);
            $this->audit->record(AuditAction::IntegrationSyncCompleted, 'tenant_integration', $integration->id, [
                'provider' => 'onesignal', 'run_id' => $run->id, 'records_read' => $run->records_read, 'removed' => $removed,
            ], $actor);

            return $run;
        } catch (Throwable $e) {
            $this->audit->record(AuditAction::IntegrationSyncFailed, 'tenant_integration', $integration->id, [
                'provider' => 'onesignal', 'run_id' => $run->id, 'category' => $e instanceof ProviderError ? $e->category : 'unexpected',
            ], $actor);

            return $this->tracker->fail($run, $integration, $e);
        }
    }

    /**
     * @return bool true when the whole file was processed
     */
    private function processFile(TenantIntegration $integration, IntegrationSyncRun $run, string $file, int $offset, ?float $sliceSeconds, float $started): bool
    {
        $handle = gzopen($file, 'rb');
        if ($handle === false) {
            throw ProviderError::schema('OneSignal export is not a readable gzip file.', 'csv_parse');
        }

        try {
            $header = fgetcsv($handle, escape: '');
            if (! is_array($header) || ! in_array('id', $header, true) || ! in_array('device_type', $header, true)) {
                throw ProviderError::schema('OneSignal export has an unexpected header.', 'csv_parse');
            }
            $columns = array_flip($header);

            for ($skipped = 0; $skipped < $offset && fgetcsv($handle, escape: '') !== false; $skipped++);

            $line = $offset;
            $chunk = [];
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                $line++;
                if ($row === [null]) {
                    continue;
                }
                $chunk[] = $row;
                if (count($chunk) === self::CHUNK) {
                    $this->storeChunk($integration, $run, $columns, $chunk, $line);
                    $chunk = [];
                    if ($sliceSeconds !== null && microtime(true) - $started >= $sliceSeconds) {
                        return false;
                    }
                }
            }
            $this->storeChunk($integration, $run, $columns, $chunk, $line);

            return true;
        } finally {
            gzclose($handle);
        }
    }

    /**
     * @param  array<string, int>  $columns
     * @param  list<list<string|null>>  $rows
     */
    private function storeChunk(TenantIntegration $integration, IntegrationSyncRun $run, array $columns, array $rows, int $line): void
    {
        $types = config('push.onesignal.push_device_types');
        $tenantId = $integration->tenant_id;
        $read = count($rows);
        $stored = 0;

        DB::transaction(function () use ($rows, $columns, $types, $tenantId, $integration, $run, &$stored) {
            $profiles = [];
            $subscriptions = [];
            foreach ($rows as $row) {
                $get = fn (string $name) => isset($columns[$name]) && isset($row[$columns[$name]]) && $row[$columns[$name]] !== '' ? (string) $row[$columns[$name]] : null;
                $deviceType = (int) $get('device_type');
                $subscriptionId = $get('id');
                if (! isset($types[$deviceType]) || $subscriptionId === null || ! Str::isUuid($subscriptionId)) {
                    continue;
                }

                $onesignalId = $get('onesignal_id');
                if ($onesignalId !== null) {
                    $profiles[$onesignalId] = [
                        'external_id' => $get('external_user_id') !== null ? mb_substr((string) $get('external_user_id'), 0, 191) : ($profiles[$onesignalId]['external_id'] ?? null),
                        'first' => self::minDate($profiles[$onesignalId]['first'] ?? null, $get('created_at')),
                        'last' => self::maxDate($profiles[$onesignalId]['last'] ?? null, $get('last_active')),
                    ];
                }

                // Tag usage: key names only, never values.
                $tags = json_decode((string) $get('tags'), true);
                if (is_array($tags) && $tags !== []) {
                    $this->tagStats['rows']++;
                    foreach (array_keys($tags) as $key) {
                        $key = mb_substr((string) $key, 0, 64);
                        $this->tagStats['keys'][$key] = ($this->tagStats['keys'][$key] ?? 0) + 1;
                    }
                }

                $subscriptions[] = [
                    'provider_subscription_id' => $subscriptionId,
                    'onesignal_id' => $onesignalId,
                    'platform' => $types[$deviceType],
                    'device_type' => $deviceType,
                    'status' => self::status($get('notification_types'), $get('invalid_identifier')),
                    'status_code' => is_numeric($get('notification_types')) ? (int) $get('notification_types') : null,
                    'device_model' => self::cut($get('device_model'), 128),
                    'device_os' => self::cut($get('device_os'), 64),
                    'app_version' => self::cut($get('game_version'), 64),
                    'language' => self::cut($get('language'), 8),
                    'country' => is_string($get('country')) && strlen((string) $get('country')) === 2 ? strtoupper((string) $get('country')) : null,
                    'session_count' => max(0, (int) $get('session_count')),
                    'first_session_at' => self::date($get('created_at')),
                    'last_session_at' => self::date($get('last_active')),
                    'last_unsubscribed_at' => self::date($get('unsubscribed_at')),
                ];
            }

            $profileIds = $this->upsertProfiles($integration, $profiles);
            foreach ($subscriptions as $s) {
                $profileId = $s['onesignal_id'] !== null ? ($profileIds[$s['onesignal_id']] ?? null) : null;
                unset($s['onesignal_id']);
                DB::statement(
                    'INSERT INTO push_subscriptions (id, tenant_id, integration_id, push_profile_id, provider, provider_subscription_id, channel, platform, device_type, status, status_code,
                        device_model, device_os, app_version, language, country, session_count, first_session_at, last_session_at, last_unsubscribed_at, last_seen_run_id, removed_at, synced_at, created_at, updated_at)
                     VALUES (?, ?, ?, ?, \'onesignal\', ?, \'push\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, now(), now(), now())
                     ON CONFLICT (tenant_id, provider, provider_subscription_id) DO UPDATE SET
                        push_profile_id = EXCLUDED.push_profile_id, platform = EXCLUDED.platform, device_type = EXCLUDED.device_type, status = EXCLUDED.status,
                        status_code = EXCLUDED.status_code, device_model = EXCLUDED.device_model, device_os = EXCLUDED.device_os, app_version = EXCLUDED.app_version,
                        language = EXCLUDED.language, country = EXCLUDED.country, session_count = EXCLUDED.session_count, first_session_at = EXCLUDED.first_session_at,
                        last_session_at = EXCLUDED.last_session_at, last_unsubscribed_at = EXCLUDED.last_unsubscribed_at, last_seen_run_id = EXCLUDED.last_seen_run_id,
                        removed_at = NULL, synced_at = now(), updated_at = now()',
                    [
                        (string) Str::uuid7(), $tenantId, $integration->id, $profileId, $s['provider_subscription_id'], $s['platform'], $s['device_type'], $s['status'], $s['status_code'],
                        $s['device_model'], $s['device_os'], $s['app_version'], $s['language'], $s['country'], $s['session_count'],
                        $s['first_session_at'], $s['last_session_at'], $s['last_unsubscribed_at'], $run->id,
                    ],
                );
                $stored++;
            }
        });

        $this->tracker->progress($run, ['read' => $read, 'updated' => $stored, 'skipped' => $read - $stored], "rows:{$line}");
        $key = "onesignal:tags:{$run->id}";
        $previous = (array) Cache::get($key, ['rows' => 0, 'keys' => []]);
        foreach ($this->tagStats['keys'] as $tag => $n) {
            $previous['keys'][$tag] = ($previous['keys'][$tag] ?? 0) + $n;
        }
        $previous['rows'] = ($previous['rows'] ?? 0) + $this->tagStats['rows'];
        Cache::put($key, $previous, now()->addDays(2));
        $this->tagStats = ['rows' => 0, 'keys' => []];
    }

    /**
     * @param  array<string, array{external_id: ?string, first: ?string, last: ?string}>  $profiles
     * @return array<string, string> onesignal id => profile id
     */
    private function upsertProfiles(TenantIntegration $integration, array $profiles): array
    {
        $ids = [];
        foreach ($profiles as $onesignalId => $p) {
            $row = DB::selectOne(
                'INSERT INTO push_profiles (id, tenant_id, integration_id, provider, provider_user_id, external_id, identity_status, first_active_at, last_active_at, synced_at, created_at, updated_at)
                 VALUES (?, ?, ?, \'onesignal\', ?, ?, ?, ?, ?, now(), now(), now())
                 ON CONFLICT (tenant_id, provider, provider_user_id) DO UPDATE SET
                    external_id = EXCLUDED.external_id,
                    identity_status = CASE WHEN push_profiles.external_id IS DISTINCT FROM EXCLUDED.external_id THEN EXCLUDED.identity_status ELSE push_profiles.identity_status END,
                    customer_id = CASE WHEN push_profiles.external_id IS DISTINCT FROM EXCLUDED.external_id THEN NULL ELSE push_profiles.customer_id END,
                    first_active_at = LEAST(push_profiles.first_active_at, EXCLUDED.first_active_at),
                    last_active_at = GREATEST(push_profiles.last_active_at, EXCLUDED.last_active_at),
                    synced_at = now(), updated_at = now()
                 RETURNING id',
                [
                    (string) Str::uuid7(), $integration->tenant_id, $integration->id, mb_substr($onesignalId, 0, 64), $p['external_id'],
                    $p['external_id'] === null ? 'no_external_id' : 'unresolved', self::date($p['first']), self::date($p['last']),
                ],
            );
            $ids[$onesignalId] = (string) $row->id;
        }

        return $ids;
    }

    private function refreshProfileCounts(TenantIntegration $integration): void
    {
        DB::update(
            'UPDATE push_profiles p SET subscription_count = sub.n, updated_at = now()
             FROM (SELECT p2.id, count(s.id) FILTER (WHERE s.removed_at IS NULL) AS n FROM push_profiles p2
                   LEFT JOIN push_subscriptions s ON s.tenant_id = p2.tenant_id AND s.push_profile_id = p2.id
                   WHERE p2.tenant_id = ? AND p2.integration_id = ? GROUP BY p2.id) sub
             WHERE p.tenant_id = ? AND p.id = sub.id AND p.subscription_count IS DISTINCT FROM sub.n',
            [$integration->tenant_id, $integration->id, $integration->tenant_id],
        );
    }

    /**
     * @param  array<string, int>  $identity
     */
    private function storeStats(TenantIntegration $integration, IntegrationSyncRun $run, int $removed, array $identity): void
    {
        $tags = (array) Cache::pull("onesignal:tags:{$run->id}", ['rows' => 0, 'keys' => []]);
        arsort($tags['keys']);
        $integration->forceFill(['settings' => array_merge((array) $integration->settings, ['last_sync' => [
            'run_id' => $run->id,
            'finished_at' => now()->toIso8601String(),
            'removed' => $removed,
            'identity' => $identity,
            'subscriptions_with_tags' => (int) $tags['rows'],
            'tag_keys' => array_slice((array) $tags['keys'], 0, 25, true),
        ]])])->save();
    }

    /**
     * notification_types: 1+ subscribed, 0 / -99 never subscribed, other
     * negatives unsubscribed (opted out, uninstalled, invalid token ...).
     */
    public static function status(?string $notificationTypes, ?string $invalidIdentifier): string
    {
        if (is_numeric($notificationTypes)) {
            $code = (int) $notificationTypes;

            return match (true) {
                $code >= 1 => 'subscribed',
                $code === 0 || $code === -99 => 'never_subscribed',
                default => 'unsubscribed',
            };
        }

        return in_array(strtolower((string) $invalidIdentifier), ['t', 'true', '1'], true) ? 'unsubscribed' : 'subscribed';
    }

    private static function date(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return is_numeric($value) ? CarbonImmutable::createFromTimestampUTC((int) $value)->toDateTimeString() : CarbonImmutable::parse($value, 'UTC')->utc()->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    private static function minDate(?string $a, ?string $b): ?string
    {
        $a = self::date($a);
        $b = self::date($b);

        return $a === null ? $b : ($b === null ? $a : min($a, $b));
    }

    private static function maxDate(?string $a, ?string $b): ?string
    {
        $a = self::date($a);
        $b = self::date($b);

        return $a === null ? $b : ($b === null ? $a : max($a, $b));
    }

    private static function cut(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
