<?php

namespace Tests\Concerns;

use App\Consent\ConsentProviderFactory;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fake İYS over Http::fake, shaped like the official API (3.2.2): token
 * endpoint, bulk status returning only ONAY addresses, changes with an
 * "after" cursor, single status. Every request is recorded.
 */
trait InteractsWithIys
{
    /** @var array<string, string> address => ONAY|RET (individual e-mail) */
    protected array $iysState = [];

    /** @var list<array<string, mixed>> */
    protected array $iysChanges = [];

    protected int $iysTokenRequests = 0;

    /** @var list<string> */
    protected array $iysPaths = [];

    /** @var (Closure(Request): mixed)|null  returns a response to override */
    protected ?Closure $iysOverride = null;

    protected function setUpIys(): void
    {
        config([
            'consent.iys.base_backoff_ms' => 1,
            'consent.iys.recipient_types' => ['INDIVIDUAL'],
            'platform.rate_limits.providers.iys' => ['tenant' => ['capacity' => 10000, 'refill_per_second' => 10000], 'global' => ['capacity' => 10000, 'refill_per_second' => 10000]],
            'platform.rate_limits.providers.iys_token' => ['global' => ['capacity' => 100, 'refill_per_second' => 100]],
            'platform.rate_limits.providers.iys_status' => ['global' => ['capacity' => 10000, 'refill_per_second' => 10000]],
        ]);
        $this->app->forgetInstance(ConsentProviderFactory::class);

        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->answerIys($request));
    }

    protected function connectIys(Tenant $tenant, string $username = 'iys-api-user', string $password = 'iys-api-password-secret'): TenantIntegration
    {
        $integration = $this->createIntegration($tenant, (string) json_encode(['username' => $username, 'password' => $password]), 'iys', 'consent');

        return $this->inTenant($tenant, function () use ($integration) {
            $integration->forceFill(['settings' => ['iys_code' => 700123, 'brand_code' => 700124, 'environment' => 'sandbox'], 'status' => 'connected'])->save();

            return $integration;
        });
    }

    private function answerIys(Request $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $this->iysPaths[] = $request->method().' '.$path;

        if ($this->iysOverride !== null && ($override = ($this->iysOverride)($request)) !== null) {
            return $override;
        }

        if ($path === '/oauth2/token') {
            $this->iysTokenRequests++;

            return Http::response(['access_token' => 'access-'.$this->iysTokenRequests, 'refresh_token' => 'refresh-'.$this->iysTokenRequests, 'expires_in' => 7200, 'refresh_expires_in' => 14400, 'token_type' => 'bearer']);
        }

        if (! str_starts_with($request->header('Authorization')[0] ?? '', 'Bearer access-')) {
            return Http::response(['errors' => [['code' => 'H351', 'message' => 'Eksik veya geçersiz jeton!']]], 401);
        }

        return match (true) {
            str_ends_with($path, '/brands') => Http::response([['brandCode' => 700124, 'name' => 'IGS', 'master' => true]]),
            str_ends_with($path, '/subscription') => Http::response(['status' => 'ACTIVE', 'subscriptionPackageName' => 'Test', 'consentLimit' => 1000000, 'usedConsentLimit' => 10]),
            (bool) preg_match('#/consents/BIREYSEL/status/EPOSTA$#', $path) => Http::response([
                'requestId' => 'r1',
                'list' => array_values(array_filter($request->data()['recipients'], fn ($r) => ($this->iysState[$r] ?? null) === 'ONAY')),
            ]),
            str_ends_with($path, '/consents/status') => isset($this->iysState[$request->data()['recipient']])
                ? Http::response(['recipient' => $request->data()['recipient'], 'recipientType' => 'BIREYSEL', 'type' => 'EPOSTA', 'status' => $this->iysState[$request->data()['recipient']],
                    'source' => 'HS_WEB', 'consentDate' => '2025-01-10 10:00:00', 'creationDate' => '2025-01-10 10:00:05', 'transactionId' => 'tx-'.md5($request->data()['recipient'])])
                : Http::response([], 200),
            str_ends_with($path, '/consents/changes') => $this->changesPage($request),
            default => Http::response(['errors' => [['code' => 'H000', 'message' => 'not faked']]], 404),
        };
    }

    private function changesPage(Request $request): mixed
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $origin = $query['source'] ?? 'IYS';
        $list = array_values(array_filter($this->iysChanges, fn ($c) => ($c['_origin'] ?? 'IYS') === $origin));
        $start = isset($query['after']) ? (int) $query['after'] : 0;
        $page = array_slice($list, $start, (int) ($query['limit'] ?? 1000));

        if ($page === []) {
            return Http::response(null, 204);
        }

        return Http::response(['after' => (string) ($start + count($page)), 'list' => array_map(fn ($c) => array_diff_key($c, ['_origin' => true]), $page)]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected static function iysChange(string $recipient, string $status, string $tx, array $overrides = []): array
    {
        return $overrides + [
            'recipient' => $recipient, 'recipientType' => 'BIREYSEL', 'type' => 'EPOSTA', 'status' => $status, 'source' => 'IYS_MOBIL',
            'consentDate' => '2026-10-20 10:00:00', 'creationDate' => '2026-10-20 10:00:05', 'transactionId' => $tx,
        ];
    }
}
