<?php

namespace Tests\Feature\Push;

use App\Audit\Actor;
use App\Connectors\OneSignal\OneSignalClientFactory;
use App\Jobs\SendPush;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Push\OneSignalSync;
use App\Push\PushAudienceCriteria;
use App\Push\PushAudienceResolver;
use App\Push\PushDelivery;
use App\Push\PushEligibility;
use App\Push\PushMessage;
use App\Push\PushSendBlocked;
use App\Push\PushSendGuard;
use App\Push\PushSendingMode;
use App\Push\SendAuthorization;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOneSignal;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class PushDeliveryTest extends TestCase
{
    use InteractsWithOneSignal, InteractsWithTenants;

    private const TESTER = 'aaaaaaaa-0000-4000-8000-000000000001';

    private const CUSTOMER = 'aaaaaaaa-0000-4000-8000-000000000002';

    private const UNSUBSCRIBED = 'aaaaaaaa-0000-4000-8000-000000000003';

    private const NEVER = 'aaaaaaaa-0000-4000-8000-000000000004';

    private Tenant $tenant;

    private TenantIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOneSignal();
        [$this->tenant] = $this->createTenant('shop');
        $this->integration = $this->connectOneSignal($this->tenant, scopes: ['read_users', 'read_subscriptions', PushSendGuard::SCOPE]);
        $this->osRows = [
            self::osRow(self::TESTER, 'u-1', '1001'),
            self::osRow(self::CUSTOMER, 'u-2', '1002'),
            self::osRow(self::UNSUBSCRIBED, 'u-3', null, -10),
            self::osRow(self::NEVER, 'u-4', null, 0),
        ];
        $this->osNotReadyPolls = 0;
        $this->inTenant($this->tenant, fn () => app(OneSignalSync::class)->run($this->integration->fresh()));
        $this->allowlist(self::TESTER);
        $this->osRequests = [];
    }

    private function allowlist(string $subscription): void
    {
        $this->inTenant($this->tenant, fn () => DB::table('test_recipients')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'channel' => 'push', 'provider' => 'onesignal', 'provider_recipient_id' => $subscription,
            'label' => 'QA device', 'approved_by' => 'owner', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    private function modes(string $platform, string $tenant): void
    {
        config(['push.sending_mode' => $platform]);
        $this->inTenant($this->tenant, fn () => DB::table('tenant_settings')->update(['sending_mode' => $tenant]));
    }

    private function authorize(array $recipients): SendAuthorization
    {
        return $this->inTenant($this->tenant, fn () => app(PushSendGuard::class)->authorize($this->integration->fresh(), $recipients, (string) Str::uuid()));
    }

    private function blockedReason(callable $fn): ?string
    {
        try {
            $fn();
        } catch (PushSendBlocked $e) {
            return $e->reason;
        }

        return null;
    }

    private static function message(): PushMessage
    {
        return new PushMessage('PratikTools', 'PratikTools OneSignal bağlantı testi');
    }

    public function test_effective_mode_is_the_stricter_of_platform_and_tenant(): void
    {
        foreach ([['off', 'live', 'off'], ['test_only', 'live', 'test_only'], ['live', 'internal', 'test_only'], ['live', 'off', 'off'], ['live', 'live', 'live'], ['bogus', 'live', 'off']] as [$platform, $tenant, $expected]) {
            $this->modes($platform, $tenant);
            $this->assertSame($expected, $this->inTenant($this->tenant, fn () => app(PushSendGuard::class)->mode()->value), "{$platform}+{$tenant}");
        }
    }

    public function test_mode_off_blocks_every_send_without_a_network_request(): void
    {
        $this->modes('off', 'live');

        $this->assertSame('SENDING_MODE_OFF', $this->blockedReason(fn () => $this->authorize([self::TESTER])));
        $this->assertSame('SENDING_MODE_OFF', $this->blockedReason(fn () => $this->inTenant($this->tenant, fn () => app(OneSignalClientFactory::class)->push($this->integration->fresh()))));
        $this->assertSame(1, Artisan::call('onesignal:test-push', ['--tenant' => 'shop', '--recipient' => [self::TESTER], '--confirm' => 'SEND REAL TEST PUSH']));
        $this->assertSame([], $this->osRequestsTo('/notifications'));
    }

    public function test_test_only_mode_allows_only_allowlisted_eligible_recipients(): void
    {
        $this->modes('test_only', 'internal');

        $this->assertSame('NOT_TEST_RECIPIENT', $this->blockedReason(fn () => $this->authorize([self::CUSTOMER])));
        $this->assertSame('NOT_TEST_RECIPIENT', $this->blockedReason(fn () => $this->authorize([self::TESTER, self::CUSTOMER])));
        $this->allowlist(self::UNSUBSCRIBED);
        $this->assertSame(PushEligibility::UNSUBSCRIBED, $this->blockedReason(fn () => $this->authorize([self::UNSUBSCRIBED])));
        $this->assertSame('RECIPIENT_COUNT', $this->blockedReason(fn () => $this->authorize([])));
        $this->assertSame([self::TESTER], $this->authorize([self::TESTER])->subscriptionIds);

        $this->modes('live', 'live');
        $this->assertSame('LIVE_NOT_AVAILABLE', $this->blockedReason(fn () => $this->authorize([self::TESTER])));
        $this->assertSame([], $this->osRequestsTo('/notifications'));
    }

    public function test_send_scope_is_required(): void
    {
        $this->modes('test_only', 'internal');
        $this->inTenant($this->tenant, fn () => $this->integration->forceFill(['scopes' => ['read_users', 'read_subscriptions']])->save());

        $this->assertSame('SEND_SCOPE_MISSING', $this->blockedReason(fn () => $this->authorize([self::TESTER])));
    }

    public function test_push_eligibility_is_its_own_channel(): void
    {
        $eligibility = app(PushEligibility::class);
        $check = fn (string $id) => $this->inTenant($this->tenant, fn () => $eligibility->forProviderSubscription('onesignal', $id));

        $this->assertSame(PushEligibility::ALLOWED, $check(self::TESTER));
        $this->assertSame(PushEligibility::UNSUBSCRIBED, $check(self::UNSUBSCRIBED));
        $this->assertSame(PushEligibility::NEVER_SUBSCRIBED, $check(self::NEVER));
        $this->assertSame(PushEligibility::UNKNOWN_SUBSCRIPTION, $check('aaaaaaaa-0000-4000-8000-000000000099'));

        $profile = $this->inTenant($this->tenant, fn () => DB::table('push_subscriptions')->where('provider_subscription_id', self::TESTER)->value('push_profile_id'));
        $this->inTenant($this->tenant, fn () => DB::table('push_suppressions')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'push_profile_id' => $profile, 'reason' => 'asked to stop']));
        $this->assertSame(PushEligibility::SUPPRESSED, $check(self::TESTER));

        // Push never reads or writes e-mail/SMS consent.
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => DB::table('consents')->count()));
    }

    public function test_an_allowlisted_test_push_is_sent_once_with_a_stable_idempotency_key(): void
    {
        $this->modes('test_only', 'internal');
        Queue::fake([SendPush::class]);

        $requestId = $this->inTenant($this->tenant, fn () => app(PushDelivery::class)->requestTestPush($this->integration->fresh(), [self::TESTER], self::message(), Actor::system()));
        Queue::assertPushedOn('send', SendPush::class);

        $delivery = app(PushDelivery::class);
        $this->assertSame('sent', $this->inTenant($this->tenant, fn () => $delivery->send($requestId, Actor::system())));
        // A duplicate delivery of the job finds the row finished.
        $this->assertSame('skipped', $this->inTenant($this->tenant, fn () => $delivery->send($requestId, Actor::system())));

        $sends = $this->osRequestsTo('/notifications');
        $this->assertCount(1, $sends);
        $body = $sends[0]->data();
        $this->assertSame([self::TESTER], $body['include_subscription_ids']);
        $this->assertSame('push', $body['target_channel']);
        $this->assertTrue(Str::isUuid($body['idempotency_key']));
        $this->assertSame($body['idempotency_key'], $this->inTenant($this->tenant, fn () => DB::table('push_send_requests')->value('idempotency_key')));
        $this->assertSame(['push.send_requested', 'push.sent'], $this->inTenant($this->tenant, fn () => DB::table('audit_logs')->where('action', 'like', 'push.%')->orderBy('created_at')->orderBy('id')->pluck('action')->all()));
    }

    public function test_retries_reuse_the_same_idempotency_key(): void
    {
        $this->modes('test_only', 'internal');
        $n = 0;
        $this->osOverride = function ($r) use (&$n) {
            return str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/notifications') && ++$n === 1 ? Http::response('', 503) : null;
        };
        Queue::fake([SendPush::class]);
        $requestId = $this->inTenant($this->tenant, fn () => app(PushDelivery::class)->requestTestPush($this->integration->fresh(), [self::TESTER], self::message(), Actor::system()));

        $this->assertSame('sent', $this->inTenant($this->tenant, fn () => app(PushDelivery::class)->send($requestId, Actor::system())));
        $keys = array_map(fn ($r) => $r->data()['idempotency_key'], $this->osRequestsTo('/notifications'));
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
    }

    public function test_the_guard_is_rechecked_at_send_time(): void
    {
        $this->modes('test_only', 'internal');
        Queue::fake([SendPush::class]);
        $requestId = $this->inTenant($this->tenant, fn () => app(PushDelivery::class)->requestTestPush($this->integration->fresh(), [self::TESTER], self::message(), Actor::system()));

        // Someone switches sending off before the job runs.
        $this->modes('off', 'internal');

        $this->assertSame('blocked', $this->inTenant($this->tenant, fn () => app(PushDelivery::class)->send($requestId, Actor::system())));
        $this->assertSame([], $this->osRequestsTo('/notifications'));
        $this->assertSame('blocked', $this->inTenant($this->tenant, fn () => DB::table('push_send_requests')->value('status')));
    }

    public function test_the_client_refuses_without_the_kill_switch_even_with_an_authorization(): void
    {
        // Mutation-style: an authorization obtained in test_only does not let
        // a send through once the platform switch is off, and recipients
        // outside the authorization are refused.
        $this->modes('test_only', 'internal');
        $authorization = $this->authorize([self::TESTER]);
        $client = $this->inTenant($this->tenant, fn () => app(OneSignalClientFactory::class)->push($this->integration->fresh()));

        $this->assertSame('RECIPIENT_NOT_AUTHORIZED', $this->blockedReason(fn () => $client->send($authorization, self::message(), [self::TESTER, self::CUSTOMER])));

        config(['push.sending_mode' => 'off']);
        $this->assertSame('SENDING_MODE_OFF', $this->blockedReason(fn () => $client->send($authorization, self::message(), [self::TESTER])));
        $this->assertSame([], $this->osRequestsTo('/notifications'));
    }

    public function test_send_authorizations_cannot_be_forged(): void
    {
        $this->expectException(\Error::class);
        new SendAuthorization('t', 'i', PushSendingMode::TestOnly, [self::TESTER], (string) Str::uuid());
    }

    public function test_malformed_send_response_fails_closed(): void
    {
        $this->modes('test_only', 'internal');
        $this->osOverride = fn ($r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/notifications') ? Http::response(['unexpected' => 1]) : null;
        Queue::fake([SendPush::class]);
        $requestId = $this->inTenant($this->tenant, fn () => app(PushDelivery::class)->requestTestPush($this->integration->fresh(), [self::TESTER], self::message(), Actor::system()));

        $this->assertSame('failed', $this->inTenant($this->tenant, fn () => app(PushDelivery::class)->send($requestId, Actor::system())));
        $this->assertSame('schema_mismatch', $this->inTenant($this->tenant, fn () => DB::table('push_send_requests')->value('error_code')));
    }

    public function test_audience_resolver_applies_push_eligibility_and_filters(): void
    {
        $resolver = app(PushAudienceResolver::class);
        $count = fn (PushAudienceCriteria $c) => $this->inTenant($this->tenant, fn () => $resolver->count($c));

        $this->assertSame(2, $count(new PushAudienceCriteria));
        $this->assertSame(1, $count(new PushAudienceCriteria(testRecipientsOnly: true)));
        $this->assertSame(0, $count(new PushAudienceCriteria(platforms: ['ios'])));
        $this->assertSame(2, $count(new PushAudienceCriteria(platforms: ['android'], activeWithinDays: 3650)));
        $this->assertSame(0, $count(new PushAudienceCriteria(requireResolvedIdentity: true)));
        $chunks = $this->inTenant($this->tenant, fn () => iterator_to_array($resolver->chunks(new PushAudienceCriteria, 1), false));
        $this->assertSame([[self::TESTER], [self::CUSTOMER]], $chunks);
    }

    public function test_test_push_command_needs_explicit_confirmation(): void
    {
        $this->modes('test_only', 'internal');
        Queue::fake([SendPush::class]);

        $this->assertSame(2, Artisan::call('onesignal:test-push', ['--tenant' => 'shop', '--recipient' => [self::TESTER]]));
        Queue::assertNothingPushed();
        $this->assertSame(0, Artisan::call('onesignal:test-push', ['--tenant' => 'shop', '--recipient' => [self::TESTER], '--confirm' => 'SEND REAL TEST PUSH']));
        Queue::assertPushed(SendPush::class, 1);
    }

    public function test_health_reports_push_without_secrets_and_never_fails_the_app(): void
    {
        $this->inTenant($this->tenant, fn () => $this->integration->forceFill(['status' => 'error'])->save());

        $health = $this->getJson('/health');
        $health->assertJsonPath('checks.onesignal.status', 'warn')->assertJsonPath('checks.onesignal.sending_mode', 'off');
        $this->assertNotSame('fail', $health->json('checks.onesignal.status'));
        $this->assertStringNotContainsString(self::API_KEY, $health->getContent());
        $this->assertSame([], array_filter($this->osRequests, fn ($r) => str_contains($r->url(), 'onesignal.com')), 'No provider call from /health.');
    }
}
