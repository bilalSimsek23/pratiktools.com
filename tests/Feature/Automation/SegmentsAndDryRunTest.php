<?php

namespace Tests\Feature\Automation;

use App\Audit\Actor;
use App\Automation\AutomationDefinitionError;
use App\Automation\Automations;
use App\Automation\DryRun;
use App\Connectors\OneSignal\OneSignalClientFactory;
use App\Connectors\OneSignal\OneSignalPushClient;
use App\Connectors\Ses\SesClientFactory;
use App\Connectors\Ses\SesEmailProvider;
use App\Consent\ConsentStatus;
use App\Email\EmailProviderResolver;
use App\Jobs\SendEmail;
use App\Jobs\SendPush;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Segments\ReadOnlyTransaction;
use App\Segments\SegmentRuleError;
use App\Segments\Segments;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\InteractsWithAutomation;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class SegmentsAndDryRunTest extends TestCase
{
    use InteractsWithAutomation, InteractsWithTenants;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        [$this->tenant] = $this->createTenant('shop');
        $this->iysSynced($this->tenant);
    }

    private function segmentError(array $definition): ?string
    {
        try {
            $this->inTenant($this->tenant, fn () => app(Segments::class)->count($definition));
        } catch (SegmentRuleError $e) {
            return $e->reason;
        }

        return null;
    }

    public function test_the_rule_dsl_only_accepts_whitelisted_typed_rules(): void
    {
        $deep = ['field' => 'order_count', 'op' => 'gte', 'value' => 1];
        for ($i = 0; $i < 5; $i++) {
            $deep = ['all' => [$deep]];
        }
        $cases = [
            'UNKNOWN_FIELD' => ['all' => [['field' => 'email', 'op' => 'eq', 'value' => 'x']]],
            'UNKNOWN_OPERATOR' => ['all' => [['field' => 'order_count', 'op' => 'like', 'value' => 1]]],
            'INVALID_VALUE' => ['all' => [['field' => 'order_count', 'op' => 'gte', 'value' => '1; DROP TABLE customers']]],
            'TOO_DEEP' => $deep,
            'TOO_MANY_RULES' => ['any' => array_fill(0, 26, ['field' => 'order_count', 'op' => 'gte', 'value' => 1])],
            'INVALID_NODE' => ['all' => [['field' => 'order_count', 'op' => 'gte', 'value' => 1, 'sql' => '1=1']]],
        ];
        foreach ($cases as $reason => $definition) {
            $this->assertSame($reason, $this->segmentError($definition), $reason);
        }
        // An injection attempt as a value is just a (non-matching) bound value.
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => app(Segments::class)->count(['all' => [['field' => 'city', 'op' => 'eq', 'value' => "x' OR '1'='1"]]])));
    }

    public function test_preview_counts_and_explains_exclusions_read_only(): void
    {
        $a = $this->shopper($this->tenant, 'a@example.com');
        $b = $this->shopper($this->tenant, 'b@example.com', ConsentStatus::Unknown);
        $c = $this->shopper($this->tenant, 'c@example.com', ConsentStatus::Revoked);
        foreach ([$a, $b, $c] as $i => $customer) {
            $this->stats($this->tenant, $customer, now()->subDays(10 + $i)->toImmutable(), 3, 3000);
        }
        $this->pushSubscriber($this->tenant, $b);
        $lowSpender = $this->shopper($this->tenant, 'd@example.com');
        $this->stats($this->tenant, $lowSpender, now()->subDays(5)->toImmutable(), 1, 100);

        $preview = $this->inTenant($this->tenant, fn () => app(Segments::class)->preview(['all' => [
            ['field' => 'order_count', 'op' => 'gte', 'value' => 2],
            ['field' => 'total_revenue', 'op' => 'gte', 'value' => 2500],
            ['field' => 'last_order_at', 'op' => 'within_last_days', 'value' => 30],
        ]]));

        $this->assertSame(3, $preview['matched']);
        $this->assertSame(1, $preview['email']['eligible']);
        $this->assertSame(['IYS_REVOKED' => 1, 'IYS_UNKNOWN' => 1], array_intersect_key($preview['email']['reasons'], ['IYS_REVOKED' => 1, 'IYS_UNKNOWN' => 1]));
        $this->assertSame(1, $preview['push']['eligible']);
        $this->assertStringNotContainsString('@', json_encode($preview));
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => app(Segments::class)->count(['all' => [['field' => 'email_marketing_eligible', 'op' => 'is', 'value' => true],
            ['field' => 'order_count', 'op' => 'gte', 'value' => 2]]])));
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => app(Segments::class)->count(['all' => [['field' => 'push_subscribed', 'op' => 'is', 'value' => true]]])));
        $this->assertSame(2, $this->inTenant($this->tenant, fn () => app(Segments::class)->count(['all' => [['field' => 'email_consent_status', 'op' => 'in', 'value' => ['GRANTED']]]])));
    }

    public function test_snapshots_are_frozen(): void
    {
        $a = $this->shopper($this->tenant, 'a@example.com');
        $this->stats($this->tenant, $a, now()->subDays(3)->toImmutable());
        $segment = $this->inTenant($this->tenant, fn () => app(Segments::class)->create('Son alıcılar', 'snapshot',
            ['all' => [['field' => 'last_order_at', 'op' => 'within_last_days', 'value' => 30]]], Actor::system()));
        $snapshot = $this->inTenant($this->tenant, fn () => app(Segments::class)->snapshot($segment, Actor::system()));

        $later = $this->shopper($this->tenant, 'later@example.com');
        $this->stats($this->tenant, $later, now()->subDays(1)->toImmutable());

        $this->assertSame(1, $this->inTenant($this->tenant, fn () => app(Segments::class)->snapshotCount($snapshot)));
        $this->assertSame(1, (int) $this->inTenant($this->tenant, fn () => DB::table('segment_snapshots')->value('member_count')));
        $this->assertSame(2, $this->inTenant($this->tenant, fn () => app(Segments::class)->count(app(Segments::class)->definition($segment))));
        $this->expectException(QueryException::class);
        $this->inTenant($this->tenant, fn () => DB::table('segment_snapshot_members')->where('snapshot_id', $snapshot)->delete());
    }

    public function test_dry_run_is_side_effect_free_and_reports_outcomes(): void
    {
        $automation = $this->abandonedCartAutomation($this->tenant, 60, publish: false);
        $ok = $this->shopper($this->tenant, 'ok@example.com');
        $unknown = $this->shopper($this->tenant, 'unknown@example.com', ConsentStatus::Unknown);
        $ordered = $this->shopper($this->tenant, 'ordered@example.com');
        $at = now()->subHours(3)->toImmutable();
        $this->cart($this->tenant, $ok, [$this->product($this->tenant)], 'OKCART', 'h1', $at);
        $this->cart($this->tenant, $unknown, [$this->product($this->tenant)], at: $at);
        $this->cart($this->tenant, $ordered, [$this->product($this->tenant)], at: $at);
        $this->cart($this->tenant, null, [$this->product($this->tenant)], at: $at);
        $this->order($this->tenant, $ordered, now()->subHour()->toImmutable());
        // The same cart changed just now: the older state is superseded.
        $this->cart($this->tenant, $ok, [$this->product($this->tenant)], 'OKCART', 'h2', now()->toImmutable());

        $before = $this->counts();
        $report = $this->inTenant($this->tenant, fn () => app(DryRun::class)->run($automation, Actor::system()));
        $after = $this->counts();

        $this->assertEquals(['audit_logs' => $before['audit_logs'] + 1] + $before, $after, 'Only the audit entry of the dry-run itself.');
        $this->assertSame(5, $report['evaluated']);
        $this->assertSame(1, $report['identity_blocked']);
        $this->assertSame(1, $report['superseded']);
        $this->assertSame(['ordered_since_trigger' => 1], array_intersect_key($report['exit_reasons'], ['ordered_since_trigger' => 1]));
        $this->assertSame(1, $report['still_waiting'], 'The newest cart is not due yet.');
        $this->assertSame([0, 0], [$report['planned_email'], $report['planned_push']]);
        $this->assertSame(1, $report['consent_blocked']);
        $this->assertSame(0, $report['provider_calls']);
        $this->assertStringNotContainsString('@', json_encode($report['samples']));
        Http::assertNothingSent();

        // The read-only transaction itself refuses writes.
        $this->expectException(QueryException::class);
        $this->inTenant($this->tenant, fn () => ReadOnlyTransaction::run(fn () => DB::table('segments')->insert(['id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenant->id, 'name' => 'x', 'type' => 'dynamic', 'definition' => '{}', 'created_at' => now(), 'updated_at' => now()])));
    }

    public function test_dry_run_counts_planned_intents_for_eligible_customers(): void
    {
        $automation = $this->abandonedCartAutomation($this->tenant, 60, publish: false);
        $ok = $this->shopper($this->tenant, 'ok@example.com');
        $this->cart($this->tenant, $ok, [$this->product($this->tenant)], at: now()->subHours(2)->toImmutable());

        $report = $this->inTenant($this->tenant, fn () => app(DryRun::class)->run($automation, Actor::system()));
        $this->assertSame([1, 0, 1], [$report['planned_email'], $report['planned_push'], $report['completed']]);
        $this->assertCount(0, $this->intents($this->tenant));
    }

    public function test_no_provider_or_send_job_is_reachable_from_automations(): void
    {
        // Mutation-style: the provider entry points throw if anything reaches
        // them, and sending modes are set to "live" to prove automations do
        // not depend on the kill switch to stay silent.
        config(['email.sending_mode' => 'live', 'push.sending_mode' => 'live']);
        $this->inTenant($this->tenant, fn () => DB::table('tenant_settings')->update(['sending_mode' => 'live']));
        $calls = 0;
        $this->app->instance(SesClientFactory::class, new class($calls) extends SesClientFactory
        {
            public function __construct(private int &$calls) {}

            public function make(TenantIntegration $integration, string $purpose, ?Actor $actor = null): SesEmailProvider
            {
                $this->calls++;
                throw new RuntimeException('SES reached from an automation');
            }
        });
        $this->app->instance(OneSignalClientFactory::class, new class($calls) extends OneSignalClientFactory
        {
            public function __construct(private int &$calls) {}

            public function push(TenantIntegration $integration, ?Actor $actor = null): OneSignalPushClient
            {
                $this->calls++;
                throw new RuntimeException('OneSignal reached from an automation');
            }
        });
        $this->app->forgetInstance(EmailProviderResolver::class);
        Queue::fake([SendEmail::class, SendPush::class]);

        $automation = $this->abandonedCartAutomation($this->tenant, 60);
        $customer = $this->shopper($this->tenant, 'ok@example.com');
        $this->pushSubscriber($this->tenant, $customer);
        $this->cart($this->tenant, $customer, [$this->product($this->tenant)]);
        $this->tick($this->tenant);
        $this->travel(61)->minutes();
        $this->tick($this->tenant);
        $this->inTenant($this->tenant, fn () => app(DryRun::class)->run($automation, Actor::system()));

        $this->assertSame('email', $this->intents($this->tenant)->sole()->channel);
        $this->assertSame(0, $calls);
        Queue::assertNotPushed(SendEmail::class);
        Queue::assertNotPushed(SendPush::class);
        Http::assertNothingSent();
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => DB::table('email_messages')->count() + DB::table('push_send_requests')->count()));
    }

    public function test_definitions_are_validated_and_state_transitions_are_explicit(): void
    {
        $bad = fn (array $trigger, array $steps) => $this->inTenant($this->tenant, function () use ($trigger, $steps) {
            try {
                app(Automations::class)->create('x'.uniqid(), $trigger, $steps, [], Actor::system());
            } catch (AutomationDefinitionError $e) {
                return $e->reason;
            }

            return null;
        });
        $this->assertSame('UNKNOWN_TRIGGER', $bad(['type' => 'sql'], [['key' => 'a', 'type' => 'exit']]));
        $this->assertSame('UNKNOWN_STEP', $bad(['type' => 'order_created'], [['key' => 'a', 'type' => 'send_email_now']]));
        $this->assertSame('INVALID_PARAM', $bad(['type' => 'order_created'], [['key' => 'a', 'type' => 'delay', 'params' => ['minutes' => 999999]]]));
        $this->assertSame('INVALID_STEP_KEY', $bad(['type' => 'order_created'], [['key' => 'a', 'type' => 'exit'], ['key' => 'a', 'type' => 'exit']]));

        $id = $this->abandonedCartAutomation($this->tenant, 60, publish: false);
        $automations = app(Automations::class);
        $this->inTenant($this->tenant, fn () => $automations->archive($id, Actor::system()));
        $this->expectException(AutomationDefinitionError::class);
        $this->inTenant($this->tenant, fn () => $automations->publish($id, Actor::system()));
    }

    public function test_cli_and_api_surface(): void
    {
        $this->assertSame(0, Artisan::call('automation:create', ['template' => 'winback', '--tenant' => 'shop', '--name' => 'Seni özledik', '--days' => 60]));
        $id = $this->inTenant($this->tenant, fn () => (string) DB::table('automations')->value('id'));
        $this->assertSame(0, Artisan::call('automation:state', ['action' => 'publish', '--tenant' => 'shop', '--automation' => 'Seni özledik']));
        $this->assertSame(0, Artisan::call('automation:list', ['--tenant' => 'shop']));
        $this->assertStringContainsString('no_purchase_for', Artisan::output());
        foreach ([['automation:dry-run', ['--automation' => $id, '--days' => [30, 90]]], ['automation:report', []], ['automation:runs', ['--automation' => $id]], ['automation:resume-due', []],
            ['segment', ['action' => 'preview', '--rules' => '{"all":[{"field":"order_count","op":"gte","value":1}]}']]] as [$command, $args]) {
            $this->assertSame(0, Artisan::call($command, ['--tenant' => 'shop'] + $args), $command);
        }

        $owner = User::query()->where('email', 'owner@shop.test')->firstOrFail();
        $token = $this->tokenFor($owner, $this->tenant);
        $this->api('GET', '/api/v1/automations', $token)->assertOk()->assertJsonPath('data.0.status', 'published');
        $detail = $this->api('GET', "/api/v1/automations/{$id}", $token)->assertOk()->json('data');
        $this->assertSame('no_purchase_for', $detail['versions'][0]['trigger']['type']);
        $this->assertArrayHasKey('runs_waiting', $detail['metrics']);
        $dry = $this->api('POST', "/api/v1/automations/{$id}/dry-run", $token)->assertOk()->json('data');
        $this->assertSame(0, $dry['provider_calls']);

        [$other, $otherOwner] = $this->createTenant('other');
        $this->api('GET', "/api/v1/automations/{$id}", $this->tokenFor($otherOwner, $other))->assertNotFound();
    }

    public function test_the_automation_tick_is_off_by_default(): void
    {
        $previous = getenv('AUTOMATION_SCHEDULE_ENABLED');
        putenv('AUTOMATION_SCHEDULE_ENABLED');
        unset($_ENV['AUTOMATION_SCHEDULE_ENABLED'], $_SERVER['AUTOMATION_SCHEDULE_ENABLED']);
        try {
            $this->assertFalse((require base_path('config/automation.php'))['schedule_enabled']);
        } finally {
            if ($previous !== false) {
                putenv("AUTOMATION_SCHEDULE_ENABLED={$previous}");
            }
        }
        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description)->filter()->values()->all();
        $this->assertNotContains('automation-tick', $events);
        $this->assertStringContainsString("AUTOMATION_SCHEDULE_ENABLED=false\n", (string) file_get_contents(base_path('.env.production.example')));
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return $this->inTenant($this->tenant, fn () => collect(['automation_runs', 'automation_run_steps', 'automation_planned_actions', 'automation_metrics_daily', 'automation_trigger_cursors',
            'email_messages', 'push_send_requests', 'consents', 'suppressions', 'customer_events', 'audit_logs'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all());
    }
}
