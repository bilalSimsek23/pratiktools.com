<?php

namespace Tests\Feature\Automation;

use App\Audit\Actor;
use App\Automation\AutomationEngine;
use App\Automation\AutomationMetrics;
use App\Automation\Automations;
use App\Automation\AutomationTemplates;
use App\Automation\LeaseLost;
use App\Automation\RunClaimer;
use App\Automation\RunProcessor;
use App\Consent\ConsentChannel;
use App\Consent\ConsentStatus;
use App\Consent\SuppressionService;
use App\Consent\SuppressionSource;
use App\Events\Customer\CustomerEventName;
use App\Events\Customer\EventRecorder;
use App\Jobs\RunAutomationTick;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithAutomation;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class AutomationEngineTest extends TestCase
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

    /**
     * @return array{0: string, 1: string, 2: string} automation, customer, cart
     */
    private function abandoned(string $email = 'ayse@example.com', ConsentStatus $iys = ConsentStatus::Granted): array
    {
        $automation = $this->abandonedCartAutomation($this->tenant, 60);
        $customer = $this->shopper($this->tenant, $email, $iys);
        $cart = $this->cart($this->tenant, $customer, [$this->product($this->tenant)]);

        return [$automation, $customer, $cart];
    }

    private function metric(string $automation, string $counter): int
    {
        return (int) $this->inTenant($this->tenant, fn () => app(AutomationMetrics::class)->report($automation)['counters'][$counter]);
    }

    public function test_abandoned_cart_waits_then_plans_one_email_intent_without_sending(): void
    {
        [$automation, $customer, $cart] = $this->abandoned();

        $this->tick($this->tenant);
        $run = $this->runs($this->tenant)->sole();
        $this->assertSame('waiting', $run->status);
        $this->assertSame(0, (int) $run->current_step);
        $this->assertCount(0, $this->intents($this->tenant));

        $this->travel(30)->minutes();
        $this->tick($this->tenant);
        $this->assertSame('waiting', $this->runs($this->tenant)->sole()->status, 'Not due yet.');

        $this->travel(31)->minutes();
        $this->tick($this->tenant);
        $run = $this->runs($this->tenant)->sole();
        $this->assertSame('completed', $run->status);
        $intent = $this->intents($this->tenant)->sole();
        $this->assertSame(['email', 'marketing', $customer], [$intent->channel, $intent->message_type, $intent->customer_id]);
        $payload = json_decode($intent->payload, true);
        $this->assertSame($cart, $payload['cart_id']);
        $this->assertSame(['price', 'stock', 'content', 'consent'], $payload['resolve_at_dispatch']);
        $this->assertStringNotContainsString('499.95', $intent->payload, 'No cart price snapshot in the intent.');
        $this->assertStringNotContainsString('ayse@', $intent->payload);
        $this->assertSame(1, $this->metric($automation, 'planned_email'));
        Http::assertNothingSent();
    }

    public function test_the_same_event_and_repeated_ticks_never_start_a_second_run(): void
    {
        [$automation] = $this->abandoned();

        $this->tick($this->tenant);
        $this->tick($this->tenant);
        // Re-read the whole source (cursor reset), as after a crash or replay.
        $this->inTenant($this->tenant, fn () => DB::table('automation_trigger_cursors')->update(['position' => null]));
        $this->tick($this->tenant);
        // A duplicate queue delivery of the tick job.
        $this->inTenant($this->tenant, fn () => (new RunAutomationTick($this->tenant->id))->handle(app(AutomationEngine::class)));

        $this->assertCount(1, $this->runs($this->tenant));
        $this->assertGreaterThanOrEqual(1, $this->metric($automation, 'duplicate_prevented'));

        $this->travel(61)->minutes();
        $this->tick($this->tenant);
        $this->tick($this->tenant);
        $this->assertCount(1, $this->intents($this->tenant));
    }

    public function test_the_database_refuses_duplicate_runs_and_actions(): void
    {
        [$automation] = $this->abandoned();
        $this->tick($this->tenant);
        $run = $this->runs($this->tenant)->sole();

        $this->expectException(QueryException::class);
        $this->inTenant($this->tenant, fn () => DB::table('automation_runs')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'automation_id' => $automation, 'version_id' => $run->version_id, 'trigger_type' => 'cart_abandoned',
            'trigger_ref' => 'other', 'idempotency_key' => str_repeat('a', 64), 'scope_key' => $run->scope_key, 'status' => 'pending', 'triggered_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_concurrent_workers_never_claim_or_write_the_same_run(): void
    {
        $this->abandoned();
        $this->tick($this->tenant);
        $this->travel(61)->minutes();

        $claimer = app(RunClaimer::class);
        $first = $this->inTenant($this->tenant, fn () => DB::transaction(fn () => $claimer->claim(10)));
        $second = $this->inTenant($this->tenant, fn () => $claimer->claim(10));
        $this->assertCount(1, $first);
        $this->assertSame([], $second, 'A claimed run is not claimable while its lease runs.');

        // A worker with a stale token writes nothing.
        $this->inTenant($this->tenant, fn () => DB::table('automation_runs')->update(['claim_token' => (string) Str::uuid()]));
        try {
            $this->inTenant($this->tenant, fn () => app(RunProcessor::class)->process($first[0]['id'], $first[0]['claim_token']));
            $this->fail('Lease lost expected.');
        } catch (LeaseLost) {
        }
        $this->assertCount(0, $this->intents($this->tenant));

        // After the lease expires another worker takes over exactly once.
        $this->travel(5)->minutes();
        $this->tick($this->tenant);
        $this->assertSame('completed', $this->runs($this->tenant)->sole()->status);
        $this->assertCount(1, $this->intents($this->tenant));
    }

    public function test_a_paused_automation_holds_waiting_runs_and_archive_cancels_them(): void
    {
        [$automation] = $this->abandoned();
        $this->tick($this->tenant);
        $this->inTenant($this->tenant, fn () => app(Automations::class)->pause($automation, Actor::system()));

        $this->travel(2)->hours();
        $this->tick($this->tenant);
        $this->assertSame('waiting', $this->runs($this->tenant)->sole()->status);
        $this->assertCount(0, $this->intents($this->tenant));

        $this->inTenant($this->tenant, fn () => app(Automations::class)->publish($automation, Actor::system()));
        $this->tick($this->tenant);
        $this->assertSame('completed', $this->runs($this->tenant)->sole()->status);

        $this->cart($this->tenant, $this->shopper($this->tenant, 'b@example.com'), [$this->product($this->tenant)]);
        $this->tick($this->tenant);
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => app(Automations::class)->archive($automation, Actor::system())));
        $this->assertSame('automation_archived', $this->runs($this->tenant)->last()->exit_reason);
        $this->assertSame(['automation.created', 'automation.published', 'automation.paused', 'automation.resumed', 'automation.archived'],
            $this->inTenant($this->tenant, fn () => DB::table('audit_logs')->where('action', 'like', 'automation.%')->orderBy('created_at')->orderBy('id')->pluck('action')->all()));
    }

    public function test_an_order_during_the_delay_exits_the_run(): void
    {
        [, $customer] = $this->abandoned();
        $this->tick($this->tenant);
        $this->travel(20)->minutes();
        $this->order($this->tenant, $customer, now()->toImmutable());

        $this->travel(45)->minutes();
        $this->tick($this->tenant);
        $run = $this->runs($this->tenant)->sole();
        $this->assertSame(['exited', 'ordered_since_trigger'], [$run->status, $run->exit_reason]);
        $this->assertCount(0, $this->intents($this->tenant));
    }

    public function test_consent_revoked_or_suppression_added_during_the_delay_blocks_the_action(): void
    {
        [$automation, $a] = $this->abandoned('a@example.com');
        $b = $this->shopper($this->tenant, 'b@example.com');
        $this->cart($this->tenant, $b, [$this->product($this->tenant)]);
        $this->tick($this->tenant);
        $this->assertCount(2, $this->runs($this->tenant)->where('status', 'waiting'));

        // Entered while eligible; İYS RET and a suppression arrive while waiting.
        $this->iys($this->tenant, 'a@example.com', ConsentStatus::Revoked);
        $recipient = $this->inTenant($this->tenant, fn () => (string) DB::table('consent_recipients')->where('address', 'b@example.com')->value('id'));
        $this->inTenant($this->tenant, fn () => app(SuppressionService::class)->suppress($recipient, SuppressionSource::Unsubscribe, ConsentChannel::Email, 'test', Actor::system()));

        $this->travel(61)->minutes();
        $this->tick($this->tenant);
        $this->assertCount(0, $this->intents($this->tenant));
        $this->assertSame(['no_eligible_channel', 'no_eligible_channel'], $this->runs($this->tenant)->pluck('exit_reason')->all());
        $this->assertSame(1, $this->metric($automation, 'consent_blocked'));
        $this->assertSame(1, $this->metric($automation, 'suppression_blocked'));
        $reasons = $this->inTenant($this->tenant, fn () => DB::table('automation_run_steps')->where('step_key', 'email')->pluck('reason')->sort()->values()->all());
        $this->assertSame(['IYS_REVOKED', 'LOCAL_SUPPRESSED'], $reasons);
    }

    public function test_iys_unknown_or_unavailable_never_plans_marketing_email(): void
    {
        [$automation] = $this->abandoned('a@example.com', ConsentStatus::Unknown);
        $this->inTenant($this->tenant, fn () => DB::table('integration_sync_cursors')->update(['last_success_at' => null]));
        $this->cart($this->tenant, $this->shopper($this->tenant, 'b@example.com'), [$this->product($this->tenant)]);
        $this->tick($this->tenant);
        $this->travel(61)->minutes();
        $this->tick($this->tenant);

        $this->assertCount(0, $this->intents($this->tenant));
        $this->assertSame(['IYS_UNAVAILABLE', 'IYS_UNAVAILABLE'], $this->inTenant($this->tenant, fn () => DB::table('automation_run_steps')->where('step_key', 'email')->pluck('reason')->all()));
        $this->assertSame(2, $this->metric($automation, 'consent_blocked'));
    }

    public function test_the_frequency_cap_spans_automations_and_ignores_transactional(): void
    {
        [$automation, $customer] = $this->abandoned();
        $this->tick($this->tenant);
        $this->travel(61)->minutes();
        $this->tick($this->tenant);
        $this->assertCount(1, $this->intents($this->tenant));

        // A second cart of the same customer the same day: capped.
        $this->cart($this->tenant, $customer, [$this->product($this->tenant)]);
        $this->tick($this->tenant);
        $this->travel(61)->minutes();
        $this->tick($this->tenant);
        $this->assertCount(1, $this->intents($this->tenant));
        $this->assertSame(1, $this->metric($automation, 'frequency_capped'));

        // A transactional flow is neither capped nor counted.
        $tx = $this->inTenant($this->tenant, function () {
            $id = app(Automations::class)->create('Sipariş bilgisi', ['type' => 'order_created', 'params' => []],
                [['key' => 'mail', 'type' => 'create_email_intent', 'params' => ['message_type' => 'transactional', 'on_ineligible' => 'exit']]], ['message_type' => 'transactional'], Actor::system());
            app(Automations::class)->publish($id, Actor::system());

            return $id;
        });
        $this->inTenant($this->tenant, fn () => app(EventRecorder::class)->record(CustomerEventName::OrderPlaced, $customer, now(), 'ticimax',
            'order:X1:placed', ['order_id' => (string) Str::uuid(), 'channel' => 'web', 'grand_total' => 10, 'currency' => 'TRY', 'item_count' => 1]));
        $this->tick($this->tenant);
        $this->assertSame(['marketing', 'transactional'], $this->intents($this->tenant)->pluck('message_type')->all());
        $this->assertSame(0, $this->metric($tx, 'frequency_capped'));
    }

    public function test_invalid_shared_or_anonymous_identities_are_blocked(): void
    {
        $automation = $this->abandonedCartAutomation($this->tenant, 60);
        $invalid = $this->inTenant($this->tenant, function () {
            $id = (string) Str::uuid7();
            DB::table('customers')->insert(['id' => $id, 'tenant_id' => $this->tenant->id, 'email' => 'not-an-email', 'created_at' => now(), 'updated_at' => now()]);
            $r = (string) Str::uuid7();
            DB::table('consent_recipients')->insert(['id' => $r, 'tenant_id' => $this->tenant->id, 'address_type' => 'email', 'address' => 'not-an-email', 'is_valid' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('recipient_customers')->insert(['tenant_id' => $this->tenant->id, 'recipient_id' => $r, 'customer_id' => $id, 'link_source' => 'test']);

            return $id;
        });
        $shared1 = $this->shopper($this->tenant, 'aile@example.com');
        $shared2 = $this->inTenant($this->tenant, function () {
            $id = (string) Str::uuid7();
            DB::table('customers')->insert(['id' => $id, 'tenant_id' => $this->tenant->id, 'email' => 'aile@example.com', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('recipient_customers')->insert(['tenant_id' => $this->tenant->id, 'recipient_id' => DB::table('consent_recipients')->where('address', 'aile@example.com')->value('id'),
                'customer_id' => $id, 'link_source' => 'test']);

            return $id;
        });
        foreach ([$invalid, $shared1, null] as $customer) {
            $this->cart($this->tenant, $customer, [$this->product($this->tenant)]);
        }
        $this->tick($this->tenant);
        $this->assertCount(2, $this->runs($this->tenant), 'The anonymous cart starts no run.');
        $this->travel(61)->minutes();
        $this->tick($this->tenant);

        $this->assertCount(0, $this->intents($this->tenant));
        $this->assertSame(['IDENTITY_CONFLICT', 'INVALID_EMAIL'], $this->inTenant($this->tenant, fn () => DB::table('automation_run_steps')->where('step_key', 'email')->pluck('reason')->sort()->values()->all()));
        $this->assertSame(3, $this->metric($automation, 'identity_blocked'));
        $this->assertNotNull($shared2);
    }

    public function test_push_is_a_separate_channel_rechecked_at_action_time(): void
    {
        $automation = $this->abandonedCartAutomation($this->tenant, 60);
        $customer = $this->shopper($this->tenant, 'push@example.com', ConsentStatus::Unknown);
        $subscription = $this->pushSubscriber($this->tenant, $customer);
        $this->cart($this->tenant, $customer, [$this->product($this->tenant)]);
        $other = $this->shopper($this->tenant, 'push2@example.com', ConsentStatus::Unknown);
        $this->pushSubscriber($this->tenant, $other);
        $this->cart($this->tenant, $other, [$this->product($this->tenant)]);
        $this->tick($this->tenant);

        // The second device unsubscribes while waiting.
        $this->inTenant($this->tenant, fn () => DB::table('push_subscriptions')->where('push_profile_id', DB::table('push_profiles')->where('customer_id', $other)->value('id'))
            ->update(['status' => 'unsubscribed']));
        $this->travel(61)->minutes();
        $this->tick($this->tenant);

        $intent = $this->intents($this->tenant)->sole();
        $this->assertSame(['push', $subscription, $customer], [$intent->channel, $intent->recipient_ref, $intent->customer_id]);
        $this->assertSame(1, $this->metric($automation, 'planned_push'));
        $this->assertSame('PUSH_UNSUBSCRIBED', $this->inTenant($this->tenant, fn () => DB::table('automation_run_steps')->where('step_key', 'push')->where('outcome', 'blocked')->value('reason')));
    }

    public function test_cart_changes_supersede_and_unavailable_items_exit(): void
    {
        $automation = $this->abandonedCartAutomation($this->tenant, 60);
        $customer = $this->shopper($this->tenant, 'a@example.com');
        $p = $this->product($this->tenant);
        $cart = $this->cart($this->tenant, $customer, [$p], 'CART1', 'h1');
        $this->tick($this->tenant);
        $this->travel(10)->minutes();
        $this->cart($this->tenant, $customer, [$p, $this->product($this->tenant, stock: 0)], 'CART1', 'h2');
        $this->tick($this->tenant);

        $runs = $this->runs($this->tenant);
        $this->assertSame(['exited:superseded', 'waiting:'], $runs->map(fn ($r) => $r->status.':'.$r->exit_reason)->all());

        // Everything sold out before the reminder: no claim about the cart.
        $this->inTenant($this->tenant, fn () => DB::table('products')->update(['total_stock' => 0]));
        $this->travel(61)->minutes();
        $this->tick($this->tenant);
        $this->assertSame('items_unavailable', $this->runs($this->tenant)->last()->exit_reason);
        $this->assertCount(0, $this->intents($this->tenant));
        $this->assertNotNull($cart.$automation);
    }

    public function test_published_versions_are_immutable_and_runs_keep_their_version(): void
    {
        [$automation] = $this->abandoned();
        $this->tick($this->tenant);
        $v1 = $this->runs($this->tenant)->sole()->version_id;

        try {
            $this->inTenant($this->tenant, fn () => DB::table('automation_versions')->where('id', $v1)->update(['steps' => '[]']));
            $this->fail('A published version must not change.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        $d = AutomationTemplates::abandonedCart(30);
        $this->inTenant($this->tenant, fn () => app(Automations::class)->change($automation, $d['trigger'], $d['steps'], $d['settings'], Actor::system()));
        $this->inTenant($this->tenant, fn () => app(Automations::class)->publish($automation, Actor::system()));
        $this->assertSame(['superseded', 'published'], $this->inTenant($this->tenant, fn () => DB::table('automation_versions')->orderBy('version')->pluck('status')->all()));

        $this->travel(61)->minutes();
        $this->tick($this->tenant);
        $run = $this->runs($this->tenant)->sole();
        $this->assertSame([$v1, 'completed'], [$run->version_id, $run->status]);
    }

    public function test_winback_starts_once_per_inactivity_episode(): void
    {
        $automation = $this->inTenant($this->tenant, function () {
            $d = AutomationTemplates::winback(90);
            $id = app(Automations::class)->create('Seni özledik', $d['trigger'], $d['steps'], $d['settings'], Actor::system(), 'winback');
            app(Automations::class)->publish($id, Actor::system());

            return $id;
        });
        $due = $this->shopper($this->tenant, 'due@example.com');
        $this->stats($this->tenant, $due, now()->subDays(92)->toImmutable());
        $this->order($this->tenant, $due, now()->subDays(92)->toImmutable());
        $recent = $this->shopper($this->tenant, 'recent@example.com');
        $this->stats($this->tenant, $recent, now()->subDays(10)->toImmutable());
        $ancient = $this->shopper($this->tenant, 'ancient@example.com');
        $this->stats($this->tenant, $ancient, now()->subDays(400)->toImmutable());

        $this->tick($this->tenant);
        $this->travel(2)->hours();
        $this->tick($this->tenant);

        $runs = $this->runs($this->tenant);
        $this->assertSame([$due], $runs->pluck('customer_id')->all(), 'Only customers crossing the threshold within the catch-up window.');
        $this->assertSame('completed', $runs->sole()->status);
        $this->assertSame('email', $this->intents($this->tenant)->sole()->channel);
        $this->assertSame(0, $this->metric($automation, 'duplicate_prevented') - $this->metric($automation, 'duplicate_prevented'));
    }

    public function test_tenants_never_see_or_drive_each_other(): void
    {
        $this->abandoned();
        $this->tick($this->tenant);
        [$other] = $this->createTenant('other');

        $this->assertSame([], $this->tick($other)['outcomes']);
        foreach (['automations', 'automation_runs', 'automation_versions', 'automation_trigger_cursors', 'automation_metrics_daily'] as $table) {
            $this->assertSame(0, $this->inTenant($other, fn () => DB::table($table)->count()), $table);
            $this->assertSame(0, DB::table($table)->count(), "{$table} without tenant context");
        }
    }

    public function test_cart_items_not_yet_linked_to_the_catalog_resolve_by_external_id(): void
    {
        [, , $cart] = $this->abandoned();
        // As after a cart sync that ran before the product sync.
        $this->inTenant($this->tenant, fn () => DB::table('cart_items')->where('cart_id', $cart)->update([
            'product_external_id' => DB::table('products')->value('external_id'), 'product_id' => null,
        ]));
        $this->tick($this->tenant);
        $this->travel(61)->minutes();
        $this->tick($this->tenant);

        $intent = $this->intents($this->tenant)->sole();
        $this->assertSame([$this->inTenant($this->tenant, fn () => DB::table('products')->value('id'))], json_decode($intent->payload, true)['product_ids']);
    }

    public function test_every_new_table_forces_rls_and_snapshots_cannot_be_updated(): void
    {
        $tables = ['segments', 'segment_snapshots', 'segment_snapshot_members', 'automations', 'automation_versions', 'automation_runs', 'automation_run_steps',
            'automation_planned_actions', 'automation_trigger_cursors', 'automation_metrics_daily'];
        $rows = DB::connection('pgsql_owner')->table('pg_class')->whereIn('relname', $tables)->where('relkind', 'r')->get(['relname', 'relrowsecurity', 'relforcerowsecurity']);
        $this->assertCount(count($tables), $rows);
        foreach ($rows as $row) {
            $this->assertTrue($row->relrowsecurity && $row->relforcerowsecurity, $row->relname);
        }
        $app = config('database.roles.app');
        foreach (['segment_snapshots', 'segment_snapshot_members'] as $table) {
            $this->assertFalse((bool) DB::connection('pgsql_owner')->selectOne("SELECT has_table_privilege(?, ?, 'UPDATE') OR has_table_privilege(?, ?, 'DELETE') AS p", [$app, $table, $app, $table])->p, $table);
        }
    }
}
