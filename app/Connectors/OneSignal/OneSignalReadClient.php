<?php

namespace App\Connectors\OneSignal;

use App\Integrations\Errors\ProviderError;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;

/**
 * Read-only OneSignal operations (verified against the current API
 * reference): view app (subscription counts), CSV export of
 * subscriptions, view user. It has no write or send method.
 */
final class OneSignalReadClient
{
    /** Columns requested from the export besides the defaults. */
    public const EXPORT_FIELDS = ['external_user_id', 'onesignal_id', 'unsubscribed_at', 'notification_types', 'country'];

    /**
     * @param  (Closure(int): void)|null  $sleep  milliseconds; replaced in tests
     */
    public function __construct(
        private readonly OneSignalTransport $transport,
        private readonly Http $http,
        private ?Closure $sleep = null,
    ) {
        $this->sleep ??= fn (int $ms) => usleep($ms * 1000);
    }

    public function transport(): OneSignalTransport
    {
        return $this->transport;
    }

    /**
     * GET /apps/{app_id}: name and subscription counts.
     *
     * @return array{name: ?string, players: ?int, messageable_players: ?int}
     */
    public function viewApp(): array
    {
        $appId = $this->transport->credentials()->appId;
        $body = $this->transport->request('view_app', 'onesignal_view', 'GET', "/apps/{$appId}");

        if (($body['id'] ?? null) !== $appId) {
            throw ProviderError::schema('OneSignal view app returned another app.', 'view_app');
        }

        return [
            'name' => is_string($body['name'] ?? null) ? $body['name'] : null,
            'players' => is_numeric($body['players'] ?? null) ? (int) $body['players'] : null,
            'messageable_players' => is_numeric($body['messageable_players'] ?? null) ? (int) $body['messageable_players'] : null,
        ];
    }

    /**
     * POST /players/csv_export: starts an export and returns the download
     * URL. The URL needs no authentication, so it is treated as a secret.
     */
    public function requestSubscriptionExport(): string
    {
        $body = $this->transport->request('csv_export', 'onesignal_view', 'POST', '/players/csv_export', ['app_id' => $this->transport->credentials()->appId], ['extra_fields' => self::EXPORT_FIELDS]);
        $url = $body['csv_file_url'] ?? null;

        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw ProviderError::schema('OneSignal export response has no https csv_file_url.', 'csv_export');
        }

        return $url;
    }

    /**
     * Downloads the gzip CSV to $path, polling while it is generated (404).
     * Returns the number of polls.
     */
    public function downloadExport(string $url, string $path, int $pollSeconds, int $maxWaitSeconds): int
    {
        $deadline = time() + $maxWaitSeconds;

        for ($polls = 1; ; $polls++) {
            try {
                $response = $this->http->connectTimeout(10)->timeout(600)->sink($path)->get($url);
            } catch (ConnectionException $e) {
                throw new ProviderError(ProviderError::RETRYABLE, 'OneSignal export download failed.', 'csv_download', previous: $e);
            }

            if ($response->successful()) {
                return $polls;
            }
            if ($response->status() !== 404 && $response->status() !== 403) {
                throw new ProviderError(ProviderError::RETRYABLE, "OneSignal export download failed (HTTP {$response->status()}).", 'csv_download');
            }
            if (time() >= $deadline) {
                throw new ProviderError(ProviderError::RETRYABLE, 'OneSignal export was not ready in time.', 'csv_download');
            }
            ($this->sleep)($pollSeconds * 1000);
        }
    }

    /**
     * GET /apps/{app_id}/users/by/{alias_label}/{alias_id}.
     *
     * @return array<string, mixed>
     */
    public function viewUser(string $aliasLabel, string $aliasId): array
    {
        $appId = $this->transport->credentials()->appId;

        return $this->transport->request('view_user', 'onesignal_view', 'GET', "/apps/{$appId}/users/by/".rawurlencode($aliasLabel).'/'.rawurlencode($aliasId));
    }
}
