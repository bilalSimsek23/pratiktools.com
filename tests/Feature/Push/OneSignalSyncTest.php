<?php

namespace Tests\Feature\Push;

use App\Audit\Actor;
use App\Commerce\Identity\CustomerIdentityService as Identities;
use App\Models\IntegrationSyncRun;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Push\OneSignalSync;
use App\Push\PushIdentityResolver;
use App\Push\PushMetrics;
use App\Tenancy\Exceptions\TenantContextMissing;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOneSignal;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class OneSignalSyncTest extends TestCase
{
    use InteractsWithOneSignal, InteractsWithTenants;

    private Tenant $tenant;

    private TenantIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOneSignal();
        [$this->tenant] = $this->createTenant('shop');
        $this->integration = $this->connectOneSignal($this->tenant);
    }

    private function sync(?Tenant $tenant = null, ?TenantIntegration $integration = null): IntegrationSyncRun
    {
        $tenant ??= $this->tenant;

        return $this->inTenant($tenant, fn () => app(OneSignalSync::class)->run(($integration ?? $this->integration)->fresh()));
    }

    private function rows(string $table, ?Tenant $tenant = null): int
    {
        return $this->inTenant($tenant ?? $this->tenant, fn () => DB::table($table)->count());
    }

    private function customer(string $email, ?string $memberId = null, ?string $phone = null): string
    {
        return $this->inTenant($this->tenant, function () use ($email, $memberId, $phone) {
            $id = (string) Str::uuid7();
            DB::table('customers')->insert(['id' => $id, 'tenant_id' => $this->tenant->id, 'email' => $email, 'phone' => $phone, 'created_at' => now(), 'updated_at' => now()]);
            $identities = app(Identities::class);
            $identities->attach($id, Identities::EMAIL, $email, 'test');
            if ($memberId !== null) {
                $identities->attach($id, Identities::MEMBER, $memberId, 'test');
            }

            return $id;
        });
    }

    public function test_full_sync_stores_push_subscriptions_without_tokens_and_skips_email_and_sms(): void
    {
        $this->osRows = [
            self::osRow('aaaaaaaa-0000-4000-8000-000000000001', 'u-1', '1001'),
            self::osRow('aaaaaaaa-0000-4000-8000-000000000002', 'u-1', '1001', 1, 0),
            self::osRow('aaaaaaaa-0000-4000-8000-000000000003', 'u-2', null, -2),
            self::osRow('aaaaaaaa-0000-4000-8000-000000000004', 'u-3', null, -99),
            self::osRow('aaaaaaaa-0000-4000-8000-000000000005', 'u-4', null, 1, 11, ['identifier' => 'someone@example.com']),
            self::osRow('aaaaaaaa-0000-4000-8000-000000000006', 'u-5', null, 1, 14, ['identifier' => '+905321112233']),
        ];
        $this->osNotReadyPolls = 2;

        $run = $this->sync();

        $this->assertSame('succeeded', $run->status, (string) $run->error_message);
        $this->assertSame(6, $run->records_read);
        $this->assertSame(4, $run->records_updated, 'E-mail and SMS subscriptions are skipped.');
        $this->assertCount(3, $this->osRequestsTo('/csv_exports/abc/users_export.csv.gz'), 'Polled while the export was generated.');
        $this->inTenant($this->tenant, function () {
            $statuses = DB::table('push_subscriptions')->pluck('status', 'provider_subscription_id')->all();
            $this->assertSame('subscribed', $statuses['aaaaaaaa-0000-4000-8000-000000000001']);
            $this->assertSame('unsubscribed', $statuses['aaaaaaaa-0000-4000-8000-000000000003']);
            $this->assertSame('never_subscribed', $statuses['aaaaaaaa-0000-4000-8000-000000000004']);
            $this->assertSame(['android', 'ios'], DB::table('push_subscriptions')->where('push_profile_id', DB::table('push_profiles')->where('provider_user_id', 'u-1')->value('id'))->orderBy('platform')->pluck('platform')->all());
            $everything = json_encode(DB::table('push_subscriptions')->get()).json_encode(DB::table('push_profiles')->get());
            $this->assertStringNotContainsString('PUSH-TOKEN', $everything);
            $this->assertStringNotContainsString('someone@example.com', $everything);
            $this->assertStringNotContainsString('905321112233', $everything);
        });
        $last = $this->inTenant($this->tenant, fn () => $this->integration->fresh()->settings['last_sync']);
        $this->assertSame(['segment' => 4, 'last_cart' => 4], $last['tag_keys']);
    }

    public function test_full_sync_is_idempotent_and_never_duplicates_subscriptions(): void
    {
        $this->osRows = [
            self::osRow('aaaaaaaa-0000-4000-8000-000000000001', 'u-1', '1001'),
            self::osRow('aaaaaaaa-0000-4000-8000-000000000001', 'u-1', '1001'),
            self::osRow('aaaaaaaa-0000-4000-8000-000000000002', 'u-2', null),
        ];

        $this->sync();
        $before = [$this->rows('push_profiles'), $this->rows('push_subscriptions')];
        $this->osNotReadyPolls = 0;
        $this->sync();

        $this->assertSame([2, 2], $before);
        $this->assertSame($before, [$this->rows('push_profiles'), $this->rows('push_subscriptions')]);
    }

    public function test_one_external_id_with_several_subscriptions_and_duplicate_external_ids_are_modelled(): void
    {
        $this->osRows = [
            self::osRow('aaaaaaaa-0000-4000-8000-000000000001', 'u-1', '1001'),
            self::osRow('aaaaaaaa-0000-4000-8000-000000000002', 'u-1', '1001', 1, 0),
            self::osRow('aaaaaaaa-0000-4000-8000-000000000003', 'u-9', '1001'),
        ];
        $this->sync();

        $metrics = $this->inTenant($this->tenant, fn () => app(PushMetrics::class)->for($this->integration->fresh()));
        $this->assertSame(2, $metrics['users']);
        $this->assertSame(1, $metrics['duplicate_external_ids']);
        $this->assertSame(1, $metrics['users_with_multiple_subscriptions']);
    }

    public function test_subscriptions_missing_from_a_later_export_are_marked_removed(): void
    {
        $this->osRows = [self::osRow('aaaaaaaa-0000-4000-8000-000000000001', 'u-1', null), self::osRow('aaaaaaaa-0000-4000-8000-000000000002', 'u-2', null)];
        $this->sync();
        $this->osRows = [self::osRow('aaaaaaaa-0000-4000-8000-000000000001', 'u-1', null)];
        $this->osNotReadyPolls = 0;
        $this->sync();

        $this->inTenant($this->tenant, fn () => $this->assertSame(1, DB::table('push_subscriptions')->whereNotNull('removed_at')->count()));
    }

    public function test_identity_resolution_uses_trusted_ids_only_and_never_merges(): void
    {
        $member = $this->customer('ayse@example.com', '1001', '+905321112233');
        $shared = $this->customer('shared@example.com');
        $this->customer('other@example.com', '2002', '+905321112233');
        // Another customer also claims shared@example.com (pending review).
        $this->inTenant($this->tenant, function () use ($shared) {
            DB::table('customer_merges')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'primary_customer_id' => $shared,
                'identity_type' => 'email', 'identity_hash' => hash('sha256', 'shared@example.com'), 'status' => 'pending_review', 'reason' => 'test', 'created_at' => now()]);
        });
        $customersBefore = $this->rows('customers');

        $this->osRows = [
            self::osRow('aaaaaaaa-0000-4000-8000-000000000001', 'u-member', '1001'),
            self::osRow('aaaaaaaa-0000-4000-8000-000000000002', 'u-none', null),
            self::osRow('aaaaaaaa-0000-4000-8000-000000000003', 'u-phone', '+905321112233'),
            self::osRow('aaaaaaaa-0000-4000-8000-000000000004', 'u-shared', 'shared@example.com'),
            self::osRow('aaaaaaaa-0000-4000-8000-000000000005', 'u-relay', 'x9@trendyolmail.com'),
            self::osRow('aaaaaaaa-0000-4000-8000-000000000006', 'u-email', 'ayse@example.com'),
        ];

        // Default strategy: nothing beyond explicit mappings.
        $this->sync();
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => DB::table('push_profiles')->whereNotNull('customer_id')->count()));

        $this->inTenant($this->tenant, fn () => $this->integration->forceFill(['settings' => ['external_id_strategy' => 'ticimax_member_id'] + $this->integration->fresh()->settings])->save());
        $this->osNotReadyPolls = 0;
        $this->sync();
        $status = fn () => $this->inTenant($this->tenant, fn () => DB::table('push_profiles')->get(['provider_user_id', 'identity_status', 'customer_id'])->keyBy('provider_user_id'));
        $profiles = $status();
        $this->assertSame($member, $profiles['u-member']->customer_id);
        $this->assertSame('no_external_id', $profiles['u-none']->identity_status);
        $this->assertNull($profiles['u-phone']->customer_id, 'Phone numbers are never matched.');

        $this->inTenant($this->tenant, fn () => $this->integration->forceFill(['settings' => ['external_id_strategy' => 'email'] + $this->integration->fresh()->settings])->save());
        $this->osNotReadyPolls = 0;
        $this->sync();
        $profiles = $status();
        $this->assertSame('conflict', $profiles['u-shared']->identity_status, 'Shared e-mail is not merged.');
        $this->assertNull($profiles['u-shared']->customer_id);
        $this->assertNull($profiles['u-relay']->customer_id, 'Marketplace relay addresses are never matched.');
        $this->assertSame($member, $profiles['u-email']->customer_id);
        $this->assertNull($profiles['u-phone']->customer_id);
        $this->assertSame($customersBefore, $this->rows('customers'), 'No customer is created or merged.');
    }

    public function test_platform_external_ids_are_opaque_and_stable(): void
    {
        $customer = $this->customer('ayse@example.com');
        $resolver = app(PushIdentityResolver::class);

        $first = $this->inTenant($this->tenant, fn () => $resolver->platformExternalId($customer, Actor::system()));
        $second = $this->inTenant($this->tenant, fn () => $resolver->platformExternalId($customer, Actor::system()));

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^ptc_[a-z0-9]{24}$/', $first);
        $this->assertStringNotContainsString($customer, $first);
        $this->assertSame([$customer, 'resolved', 'mapping:platform'], $this->inTenant($this->tenant, fn () => $resolver->resolve($first, 'none')));
    }

    public function test_tenant_a_cannot_see_tenant_b_push_data_and_no_context_fails_closed(): void
    {
        $this->osRows = [self::osRow('aaaaaaaa-0000-4000-8000-000000000001', 'u-1', '1001')];
        $this->sync();
        [$other] = $this->createTenant('other');

        foreach (['push_profiles', 'push_subscriptions', 'push_send_requests', 'test_recipients'] as $table) {
            $this->assertSame(0, $this->rows($table, $other), $table);
        }

        $this->expectException(TenantContextMissing::class);
        app(OneSignalSync::class)->run($this->integration);
    }

    public function test_rls_is_forced_on_every_push_table(): void
    {
        $rows = DB::connection('pgsql_owner')->select("SELECT relname, relrowsecurity AS rls, relforcerowsecurity AS forced FROM pg_class WHERE relname IN ('push_profiles', 'push_subscriptions', 'push_identity_mappings', 'push_suppressions', 'test_recipients', 'push_send_requests')");

        $this->assertCount(6, $rows);
        foreach ($rows as $r) {
            $this->assertTrue($r->rls && $r->forced, $r->relname);
        }
    }

    public function test_tenant_context_survives_a_reconnect_during_sync(): void
    {
        [$other] = $this->createTenant('other');
        $this->osRows = [self::osRow('aaaaaaaa-0000-4000-8000-000000000001', 'u-1', null), self::osRow('aaaaaaaa-0000-4000-8000-000000000002', 'u-2', null)];
        $this->osOverride = function (Request $r) {
            if (str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/csv_export')) {
                DB::reconnect('pgsql');
            }

            return null;
        };

        $this->assertSame('succeeded', $this->sync()->status);
        $this->assertSame(2, $this->rows('push_subscriptions'));
        $this->assertSame(0, $this->rows('push_subscriptions', $other));
    }

    public function test_api_errors_are_classified_and_retried_within_bounds(): void
    {
        $responses = ['401' => Http::response(['errors' => ['Access denied']], 401), '429' => Http::response(['errors' => ['API rate limit exceeded']], 429, ['Retry-After' => '3']), '500' => Http::response('', 503)];

        // 401: auth, no retry.
        $this->osOverride = fn (Request $r) => str_contains($r->url(), '/players/csv_export') ? $responses['401'] : null;
        $run = $this->sync();
        $this->assertSame('auth', $run->error_category);
        $this->assertCount(1, $this->osRequestsTo('/players/csv_export'));
        $this->assertSame('action_required', $this->inTenant($this->tenant, fn () => $this->integration->fresh()->status));

        // 429 twice then success: waits at least Retry-After.
        $this->inTenant($this->tenant, fn () => $this->integration->forceFill(['status' => 'connected'])->save());
        $this->osRequests = [];
        $this->osSleeps = [];
        $n = 0;
        $this->osOverride = function (Request $r) use (&$n, $responses) {
            return str_contains($r->url(), '/players/csv_export') && ++$n <= 2 ? $responses['429'] : null;
        };
        $this->osRows = [self::osRow('aaaaaaaa-0000-4000-8000-000000000001', 'u-1', null)];
        $this->osNotReadyPolls = 0;
        $this->assertSame('succeeded', $this->sync()->status);
        $this->assertCount(3, $this->osRequestsTo('/players/csv_export'));
        $this->assertGreaterThanOrEqual(3000, min(array_slice($this->osSleeps, 0, 2)));

        // 5xx: bounded (3 attempts), then retryable failure.
        $this->osRequests = [];
        $this->osOverride = fn (Request $r) => str_contains($r->url(), '/players/csv_export') ? $responses['500'] : null;
        $run = $this->sync();
        $this->assertSame('retryable', $run->error_category);
        $this->assertCount(3, $this->osRequestsTo('/players/csv_export'));
    }

    public function test_timeouts_and_malformed_responses_fail_closed(): void
    {
        $this->osOverride = fn (Request $r) => str_contains($r->url(), '/players/csv_export') ? throw new ConnectionException('timed out') : null;
        $this->assertSame('retryable', $this->sync()->error_category);

        $this->inTenant($this->tenant, fn () => $this->integration->forceFill(['status' => 'connected'])->save());
        $this->osOverride = fn (Request $r) => str_contains($r->url(), '/players/csv_export') ? Http::response(['unexpected' => true]) : null;
        $this->assertSame('schema_mismatch', $this->sync()->error_category);

        $this->osOverride = fn (Request $r) => str_contains($r->url(), '/csv_exports/') ? Http::response(gzencode("foo,bar\n1,2\n")) : null;
        $this->osNotReadyPolls = 0;
        $this->assertSame('schema_mismatch', $this->sync()->error_category);
        $this->assertSame(0, $this->rows('push_subscriptions'));
    }

    public function test_api_key_never_reaches_logs_audit_or_api_responses(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });
        $this->osRows = [self::osRow('aaaaaaaa-0000-4000-8000-000000000001', 'u-1', '1001')];
        $this->sync();

        $owner = User::query()->where('email', 'owner@shop.test')->firstOrFail();
        $token = $this->tokenFor($owner, $this->tenant);
        $bodies = $this->api('GET', "/api/v1/integrations/{$this->integration->id}", $token)->getContent()
            .$this->api('GET', "/api/v1/integrations/{$this->integration->id}/push-metrics", $token)->assertOk()->getContent()
            .$this->getJson('/health')->getContent();
        $audit = json_encode($this->inTenant($this->tenant, fn () => DB::table('audit_logs')->get()));

        foreach ([self::API_KEY, 'PUSH-TOKEN'] as $secret) {
            $this->assertStringNotContainsString($secret, implode("\n", $logged));
            $this->assertStringNotContainsString($secret, $bodies);
            $this->assertStringNotContainsString($secret, (string) $audit);
        }
        $this->assertStringContainsString('integration.sync_completed', (string) $audit);
    }
}
