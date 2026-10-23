<?php

namespace Tests\Feature\Consent;

use App\Connectors\Iys\IysClient;
use App\Connectors\Iys\IysConsentConnector;
use App\Connectors\Iys\IysTokenManager;
use App\Connectors\Iys\ReadOnlyModeViolation;
use App\Consent\ConsentChannel;
use App\Consent\ConsentProviderFactory;
use App\Consent\ConsentReports;
use App\Consent\RecipientType;
use App\Consent\SendEligibility;
use App\Consent\Sync\ConsentSynchronizer;
use App\Integrations\Errors\ProviderError;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\RateLimiting\ProviderRateLimiter;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithIys;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class IysShadowSyncTest extends TestCase
{
    use InteractsWithIys, InteractsWithTenants;

    private Tenant $tenant;

    private TenantIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIys();
        [$this->tenant] = $this->createTenant('shop');
        $this->integration = $this->connectIys($this->tenant);
    }

    private function connector(): IysConsentConnector
    {
        /** @var IysConsentConnector */
        return $this->inTenant($this->tenant, fn () => app(ConsentProviderFactory::class)->for($this->integration->fresh(), 'test'));
    }

    private function sync(): ConsentSynchronizer
    {
        return app(ConsentSynchronizer::class);
    }

    private function customers(int $n, string $prefix = 'm'): void
    {
        $this->inTenant($this->tenant, function () use ($n, $prefix) {
            $rows = [];
            for ($i = 1; $i <= $n; $i++) {
                $rows[] = ['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'email' => "{$prefix}{$i}@example.com", 'source_consent' => json_encode(['email_opt_in' => true]), 'attributes' => '{}', 'created_at' => now(), 'updated_at' => now()];
            }
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('customers')->insert($chunk);
            }
            app(ConsentSynchronizer::class)->buildFromCustomers();
        });
    }

    public function test_token_is_obtained_once_cached_encrypted_and_reused(): void
    {
        $c = $this->connector();
        $c->authenticate();
        $c->getQuota();
        $this->connector()->authenticate();

        $this->assertSame(1, $this->iysTokenRequests);
        $cached = Cache::get($c->client()->credentials()->tokenKey());
        $this->assertIsString($cached);
        $this->assertStringNotContainsString('access-1', $cached);
        $this->assertStringNotContainsString('refresh-1', $cached);
        $this->assertSame(0, DB::table('tenant_integrations')->where('settings', 'like', '%access-%')->count(), 'Tokens never reach the database.');
    }

    public function test_401_refreshes_once_with_the_refresh_token_and_retries(): void
    {
        $c = $this->connector();
        $c->authenticate();
        $grants = [];
        $this->iysOverride = function (Request $r) use (&$grants) {
            if (str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/oauth2/token')) {
                $grants[] = $r->data()['grant_type'];
            }
            if (($r->header('Authorization')[0] ?? '') === 'Bearer access-1') {
                return Http::response(['errors' => [['code' => 'H351']]], 401);
            }

            return null;
        };

        $this->assertSame('ACTIVE', $c->getQuota()['status']);
        $this->assertSame(['refresh_token'], $grants);
    }

    public function test_concurrent_401s_cause_a_single_refresh(): void
    {
        $credentials = $this->connector()->client()->credentials();
        $requests = 0;
        $manager = function () use (&$requests) {
            return new IysTokenManager(Cache::store(), Cache::store()->getStore(), app('encrypter'), app(ProviderRateLimiter::class), function (array $fields) use (&$requests) {
                $requests++;

                return ['access_token' => "access-{$requests}", 'refresh_token' => "refresh-{$requests}", 'expires_in' => 7200, 'refresh_expires_in' => 14400];
            });
        };
        [$workerA, $workerB] = [$manager(), $manager()];

        $tokenA = $workerA->accessToken($credentials);
        $tokenB = $workerB->accessToken($credentials);
        $this->assertSame($tokenA, $tokenB, 'Shared cache: one token for both workers.');

        // Both get 401 for the same token; only the first refreshes.
        $newA = $workerA->accessToken($credentials, rejected: $tokenA);
        $newB = $workerB->accessToken($credentials, rejected: $tokenB);

        $this->assertSame('access-2', $newA);
        $this->assertSame($newA, $newB);
        $this->assertSame(2, $requests);
    }

    public function test_token_budget_is_respected_platform_wide(): void
    {
        config(['platform.rate_limits.providers.iys_token' => ['global' => ['capacity' => 1, 'refill_per_second' => 1 / 3600]]]);
        $this->connector()->authenticate();
        Cache::flush();

        try {
            $this->connector()->authenticate();
            $this->fail('Expected the token budget to stop the second password grant.');
        } catch (ProviderError $e) {
            $this->assertSame(ProviderError::RATE_LIMITED, $e->category);
        }
        $this->assertSame(1, $this->iysTokenRequests);
    }

    public function test_429_is_retried_with_backoff(): void
    {
        $calls = 0;
        $this->iysOverride = function (Request $r) use (&$calls) {
            if (str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/subscription') && ++$calls <= 2) {
                return Http::response(['errors' => [['code' => 'H124', 'message' => 'Saniyede kabul edilen istek limitine ulaşıldı.']]], 429);
            }

            return null;
        };

        $this->assertSame('ACTIVE', $this->connector()->getQuota()['status']);
        $this->assertSame(3, $calls);
    }

    public function test_write_operations_are_refused_before_any_request(): void
    {
        $client = $this->connector()->client();
        $before = count($this->iysPaths);

        foreach (array_merge(IysClient::WRITE_OPERATIONS, ['anything_else']) as $op) {
            try {
                $client->call($op, json: ['recipient' => 'x@example.com', 'status' => 'ONAY']);
                $this->fail("{$op} must be refused.");
            } catch (ReadOnlyModeViolation) {
            }
        }

        $this->assertSame($before, count($this->iysPaths), 'No network call, not even for a token.');
        foreach ($this->iysPaths as $path) {
            $this->assertDoesNotMatchRegularExpression('#^(PUT|DELETE)|/consents$|/consents/request$|/push/registration$|/agreements/[^/]+$#', $path);
        }
    }

    public function test_bulk_status_sync_batches_and_classifies(): void
    {
        $this->customers(2500);
        $this->iysState = ['m1@example.com' => 'ONAY', 'm1500@example.com' => 'ONAY', 'm2500@example.com' => 'RET'];

        $run = $this->inTenant($this->tenant, fn () => $this->sync()->iysStatus($this->integration->fresh()));

        $this->assertSame('succeeded', $run->status, (string) $run->error_message);
        $this->assertSame(2500, $run->records_read);
        $this->assertSame(3, collect($this->iysPaths)->filter(fn ($p) => str_contains($p, '/status/EPOSTA'))->count(), '1,000 addresses per call.');
        $this->inTenant($this->tenant, function () {
            $this->assertSame(2, DB::table('consents')->where('source_provider', 'IYS')->where('status', 'GRANTED')->count());
            $this->assertSame(2498, DB::table('consents')->where('source_provider', 'IYS')->where('status', 'UNKNOWN')->count(), 'Bulk cannot tell RET from no record.');
        });

        // Resolution by single status: RET found, others stay unknown with "no record".
        $resolve = $this->inTenant($this->tenant, fn () => $this->sync()->iysResolve($this->integration->fresh(), 5));
        $this->assertSame('succeeded', $resolve->status);
        $this->inTenant($this->tenant, function () {
            $this->assertSame(0, DB::table('consents')->where('source_provider', 'IYS')->where('status', 'REVOKED')->count(), 'm2500 not in the first 5 by priority/id.');
        });
        $this->inTenant($this->tenant, fn () => DB::table('consents')->where('source_provider', 'IYS')->where('status', 'UNKNOWN')->update(['metadata' => DB::raw("metadata - 'resolved'")]));
        $this->inTenant($this->tenant, fn () => $this->sync()->iysResolve($this->integration->fresh(), 3000));
        $this->inTenant($this->tenant, function () {
            $this->assertSame(1, DB::table('consents')->where('source_provider', 'IYS')->where('status', 'REVOKED')->count());
            $this->assertSame(2497, DB::table('consents')->where('source_provider', 'IYS')->where('status', 'UNKNOWN')->whereRaw("metadata->>'resolved' = 'no_record'")->count());
        });

        $reasons = $this->inTenant($this->tenant, fn () => app(SendEligibility::class)->reasonCounts());
        $this->assertSame(['ALLOWED' => 2, 'IYS_REVOKED' => 1, 'IYS_UNKNOWN' => 2497], collect($reasons)->sortKeys()->all());
    }

    public function test_a_later_bulk_run_keeps_a_known_ret(): void
    {
        $this->customers(3);
        $this->iysState = ['m2@example.com' => 'RET'];
        $this->inTenant($this->tenant, fn () => $this->sync()->iysStatus($this->integration->fresh()));
        $this->inTenant($this->tenant, fn () => $this->sync()->iysResolve($this->integration->fresh(), 10));
        $this->inTenant($this->tenant, fn () => $this->sync()->iysStatus($this->integration->fresh()));

        $this->inTenant($this->tenant, fn () => $this->assertSame('REVOKED', DB::table('consents as c')->join('consent_recipients as r', 'r.id', '=', 'c.recipient_id')
            ->where('r.address', 'm2@example.com')->where('c.source_provider', 'IYS')->value('c.status')));
    }

    public function test_changes_pull_advances_the_cursor_only_on_success_and_is_idempotent(): void
    {
        $this->customers(2);
        $this->iysChanges = [
            self::iysChange('m1@example.com', 'ONAY', 'tx1'),
            self::iysChange('m1@example.com', 'RET', 'tx2', ['creationDate' => '2026-10-21 09:00:00']),
            self::iysChange('new@example.com', 'ONAY', 'tx3', ['_origin' => 'HS', 'source' => 'HS_WEB']),
        ];

        // A failing call: no cursor.
        $this->iysOverride = fn (Request $r) => str_contains($r->url(), '/consents/changes') ? Http::response(['errors' => [['code' => 'H470']]], 500) : null;
        $failed = $this->inTenant($this->tenant, fn () => $this->sync()->iysChanges($this->integration->fresh(), 'citizen'));
        $this->assertSame('failed', $failed->status);
        $this->assertNull($this->inTenant($this->tenant, fn () => DB::table('integration_sync_cursors')->where('entity', 'iys_changes_citizen')->value('provider_cursor')));
        $this->assertSame('error', $this->inTenant($this->tenant, fn () => $this->integration->fresh()->status));

        $this->iysOverride = null;
        $this->inTenant($this->tenant, fn () => $this->sync()->iysChanges($this->integration->fresh(), 'citizen'));
        $this->inTenant($this->tenant, fn () => $this->sync()->iysChanges($this->integration->fresh(), 'provider'));
        $this->assertSame('2', $this->inTenant($this->tenant, fn () => DB::table('integration_sync_cursors')->where('entity', 'iys_changes_citizen')->value('provider_cursor')));

        // Replaying the same movements adds nothing.
        $this->inTenant($this->tenant, fn () => DB::table('integration_sync_cursors')->update(['provider_cursor' => null]));
        $this->inTenant($this->tenant, fn () => $this->sync()->iysChanges($this->integration->fresh(), 'citizen'));

        $this->inTenant($this->tenant, function () {
            $this->assertSame(1, DB::table('consent_events')->where('provider_transaction_id', 'tx2')->count());
            $this->assertSame(2, DB::table('consent_events')->where('source_provider', 'IYS')->whereIn('provider_transaction_id', ['tx1', 'tx2'])->count());
            $m1 = DB::table('consents as c')->join('consent_recipients as r', 'r.id', '=', 'c.recipient_id')->where('r.address', 'm1@example.com')->where('c.source_provider', 'IYS');
            $this->assertSame('REVOKED', $m1->value('c.status'), 'The later RET wins.');
            $this->assertSame(1, DB::table('consent_recipients')->where('address', 'new@example.com')->count(), 'İYS-only address kept as recipient.');
            $this->assertSame(0, DB::table('recipient_customers')->join('consent_recipients', 'consent_recipients.id', '=', 'recipient_customers.recipient_id')->where('address', 'new@example.com')->count());
        });
    }

    public function test_a_cursor_older_than_the_seven_day_window_is_reset_and_reported(): void
    {
        $this->inTenant($this->tenant, fn () => DB::table('integration_sync_cursors')->insert([
            'tenant_id' => $this->tenant->id, 'integration_id' => $this->integration->id, 'entity' => 'iys_changes_citizen',
            'provider_cursor' => '999', 'last_success_at' => now()->subDays(8), 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->iysChanges = [self::iysChange('x@example.com', 'ONAY', 'tx9')];

        $run = $this->inTenant($this->tenant, fn () => $this->sync()->iysChanges($this->integration->fresh(), 'citizen'));

        $this->assertSame('succeeded', $run->status);
        $this->assertSame(1, $run->records_read, 'Read from the start of the available window.');
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => DB::table('integration_errors')->where('operation', 'consent_changes')->where('message', 'like', '%7-day%')->count()));
    }

    public function test_bulk_query_refuses_more_than_one_thousand_addresses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->connector()->bulkQueryGranted(array_map(fn ($i) => "a{$i}@x.com", range(1, 1001)), ConsentChannel::Email, RecipientType::Individual);
    }

    public function test_production_is_refused_without_registered_static_egress_ips(): void
    {
        $this->inTenant($this->tenant, fn () => $this->integration->forceFill(['settings' => ['iys_code' => 1, 'brand_code' => 2, 'environment' => 'production']])->save());
        config(['consent.iys.allow_production' => true, 'platform.egress_static_ips' => []]);

        $this->expectExceptionMessage('static egress IPs');
        $this->connector();
    }

    public function test_secrets_never_reach_logs_or_api_responses(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        config(['logging.default' => 'stack']);
        $this->customers(3);
        $this->iysState = ['m1@example.com' => 'ONAY'];
        $this->inTenant($this->tenant, fn () => $this->sync()->iysStatus($this->integration->fresh()));
        $this->inTenant($this->tenant, fn () => $this->sync()->iysResolve($this->integration->fresh(), 10));

        $all = implode("\n", $logged);
        foreach (['iys-api-password-secret', 'iys-api-user', 'access-1', 'refresh-1', 'm1@example.com'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }

        [, $owner] = [null, User::query()->where('email', 'owner@shop.test')->firstOrFail()];
        $body = $this->api('GET', "/api/v1/integrations/{$this->integration->id}", $this->tokenFor($owner, $this->tenant))->assertOk()->getContent();
        $this->assertStringNotContainsString('iys-api-password-secret', $body);
        $this->assertStringNotContainsString('iys-api-user', $body);
    }

    public function test_push_receiver_requires_basic_auth_and_is_idempotent(): void
    {
        $this->customers(1);
        $this->inTenant($this->tenant, fn () => $this->integration->forceFill(['settings' => $this->integration->settings + ['push_username' => 'iys-push', 'push_password_hash' => bcrypt('push-secret')]])->save());
        $url = '/webhooks/iys/'.$this->tenant->public_id;
        $payload = ['list' => [self::iysChange('m1@example.com', 'RET', 'push-tx-1', ['source' => 'IYS_MOBIL'])]];

        $this->postJson($url, $payload)->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Basic '.base64_encode('iys-push:wrong')])->postJson($url, $payload)->assertUnauthorized();
        $this->flushHeaders();
        $this->withHeaders(['Authorization' => 'Basic '.base64_encode('iys-push:push-secret')])->postJson($url, $payload)->assertStatus(202);
        $this->withHeaders(['Authorization' => 'Basic '.base64_encode('iys-push:push-secret')])->postJson($url, $payload)->assertStatus(202);

        $this->inTenant($this->tenant, function () {
            $this->assertSame(1, DB::table('consent_events')->where('provider_transaction_id', 'push-tx-1')->count());
            $this->assertSame('REVOKED', DB::table('consents')->where('source_provider', 'IYS')->value('status'));
        });
    }

    public function test_consent_api_shows_counts_and_masked_conflicts_only(): void
    {
        $this->customers(3);
        $this->iysState = ['m1@example.com' => 'ONAY', 'm2@example.com' => 'RET'];
        $this->inTenant($this->tenant, fn () => $this->sync()->iysStatus($this->integration->fresh()));
        $this->inTenant($this->tenant, fn () => $this->sync()->iysResolve($this->integration->fresh(), 10));
        $this->inTenant($this->tenant, fn () => app(ConsentReports::class)->refreshConflicts());
        $owner = User::query()->where('email', 'owner@shop.test')->firstOrFail();
        $token = $this->tokenFor($owner, $this->tenant);

        $this->api('GET', '/api/v1/consent/overview', $token)->assertOk()->assertJsonPath('data.sendable', 1)->assertJsonPath('data.ret', 1);
        $this->api('GET', '/api/v1/consent/reconciliation', $token)->assertOk()->assertJsonPath('data.matrix.B.count', 1);
        $conflicts = $this->api('GET', '/api/v1/consent/conflicts', $token)->assertOk();
        $conflicts->assertJsonPath('data.0.reason', 'STORE_GRANTED_IYS_REVOKED')->assertJsonPath('data.0.recipient', 'm***@example.com');
        $this->assertStringNotContainsString('m2@example.com', $conflicts->getContent());

        [$other, $otherOwner] = $this->createTenant('other');
        $this->api('GET', '/api/v1/consent/overview', $this->tokenFor($otherOwner, $other))->assertOk()->assertJsonPath('data.total_recipients', 0);
    }
}
