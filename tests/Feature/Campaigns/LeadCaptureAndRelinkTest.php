<?php

namespace Tests\Feature\Campaigns;

use App\Audit\Actor;
use App\Automation\Automations;
use App\Commerce\Sync\CommerceRelink;
use App\Consent\ConsentStatus;
use App\Consent\SendEligibility;
use App\Leads\LeadForms;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithAutomation;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class LeadCaptureAndRelinkTest extends TestCase
{
    use InteractsWithAutomation, InteractsWithTenants;

    private const ORIGIN = 'https://www.igs.example';

    private Tenant $tenant;

    private string $form;

    private string $publicId;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        [$this->tenant] = $this->createTenant('shop');
        $this->iysSynced($this->tenant);
        [$this->form, $this->publicId] = $this->form($this->tenant);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function form(Tenant $tenant, bool $active = true, bool $doubleOptIn = false): array
    {
        return $this->inTenant($tenant, function () use ($active, $doubleOptIn) {
            $forms = app(LeadForms::class);
            $id = $forms->create('Footer '.Str::random(4), 'footer', 'Kampanya ve duyurulardan e-posta ile haberdar olmak istiyorum.', 'Teşekkürler, talebiniz alındı.',
                [self::ORIGIN], Actor::system(), doubleOptIn: $doubleOptIn);
            if ($active) {
                $forms->setActive($id, true, Actor::system());
            }

            return [$id, (string) DB::table('lead_forms')->where('id', $id)->value('public_id')];
        });
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function submit(array $body, ?string $publicId = null, ?string $origin = self::ORIGIN, ?string $raw = null): TestResponse
    {
        return $this->call('POST', '/public/v1/forms/'.($publicId ?? $this->publicId).'/subscribe', [], [], [],
            array_filter(['HTTP_ORIGIN' => $origin, 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.'.random_int(1, 250)]), $raw ?? (string) json_encode($body));
    }

    private function rowCount(string $table): int
    {
        return $this->inTenant($this->tenant, fn () => DB::table($table)->count());
    }

    public function test_a_submission_records_store_side_consent_only_and_stays_fail_closed(): void
    {
        $this->submit(['email' => ' Yeni.Uye@Example.com ', 'consent' => true, 'page_url' => 'https://www.igs.example/kampanya?email=x@y.z&token=secret', 'utm_source' => 'instagram',
            'utm_campaign' => 'kasim', 'locale' => 'tr'])->assertStatus(202)->assertExactJson(['status' => 'received', 'message' => 'Teşekkürler, talebiniz alındı.']);

        $s = $this->inTenant($this->tenant, fn () => DB::table('lead_submissions')->sole());
        $this->assertSame(['accepted', true, '/kampanya', 'www.igs.example', 'instagram', 'footer'], [$s->outcome, $s->consent_checked, $s->page_path, $s->page_host, $s->utm_source, $s->placement]);
        $this->assertStringNotContainsString('secret', json_encode($s));
        $this->assertStringNotContainsString('uye@', json_encode($s), 'No address on the submission (hash only).');
        $recipient = $this->inTenant($this->tenant, fn () => DB::table('consent_recipients')->where('address', 'yeni.uye@example.com')->value('id'));
        $consent = $this->inTenant($this->tenant, fn () => DB::table('consents')->where('recipient_id', $recipient)->sole());
        $this->assertSame(['LEAD_FORM', 'GRANTED'], [$consent->source_provider, $consent->status]);
        $evidence = json_decode($consent->evidence, true);
        $this->assertSame(1, $evidence['consent_version']);
        $this->assertSame(64, strlen($evidence['consent_text_hash']));
        $this->assertArrayNotHasKey('ip', $evidence);
        $this->assertSame(['IYS', 'ONAY', 'pending_write_disabled'], $this->inTenant($this->tenant, fn () => array_values((array) DB::table('consent_write_intents')->first(['target', 'status', 'state']))));
        // A checkbox is not İYS ONAY.
        $this->assertSame('IYS_UNKNOWN', $this->inTenant($this->tenant, fn () => app(SendEligibility::class)->forRecipient($recipient)->reason->value));
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => DB::table('customer_events')->where('name', 'NEWSLETTER_SUBSCRIBED')->count()));
    }

    public function test_responses_never_reveal_whether_an_address_is_known(): void
    {
        $this->shopper($this->tenant, 'musteri@example.com');
        $responses = [];
        foreach (['musteri@example.com', 'hic-gorulmedi@example.com', 'musteri@example.com'] as $email) {
            $r = $this->submit(['email' => $email, 'consent' => true]);
            $responses[] = [$r->status(), $r->getContent()];
        }
        $honeypot = $this->submit(['email' => 'bot@example.com', 'consent' => true, 'website' => 'http://spam.test']);
        $responses[] = [$honeypot->status(), $honeypot->getContent()];

        $this->assertCount(1, array_unique(array_map('json_encode', $responses)));
        $this->assertSame(2, $this->rowCount('lead_submissions'), 'Duplicate and bot submissions are not stored.');
    }

    public function test_duplicate_posts_create_one_submission_consent_event_and_newsletter_event(): void
    {
        $id = (string) Str::uuid();
        foreach (range(1, 3) as $i) {
            $this->submit(['email' => 'tekrar@example.com', 'consent' => true, 'submission_id' => $id])->assertStatus(202);
        }
        $this->submit(['email' => 'tekrar@example.com', 'consent' => true])->assertStatus(202);
        $this->submit(['email' => 'tekrar@example.com', 'consent' => true])->assertStatus(202);

        $this->assertSame(2, $this->rowCount('lead_submissions'), 'One per client submission id, one per day otherwise.');
        $this->assertSame(2, $this->inTenant($this->tenant, fn () => DB::table('customer_events')->where('name', 'NEWSLETTER_SUBSCRIBED')->count()), 'One event per stored submission.');
        $this->assertSame(1, $this->rowCount('consents'));
        $this->assertSame(2, $this->rowCount('consent_write_intents'));
        $counters = $this->inTenant($this->tenant, fn () => DB::table('lead_metrics_daily')->first());
        $this->assertSame([5, 2, 3], [(int) $counters->submissions, (int) $counters->accepted, (int) $counters->duplicate]);
    }

    public function test_invalid_oversized_inactive_foreign_or_spoofed_requests_are_refused(): void
    {
        $this->submit(['email' => 'not-an-email', 'consent' => true])->assertStatus(422);
        $this->submit(['email' => "a@example.com\r\nBcc: x@y.z", 'consent' => true])->assertStatus(422);
        $this->submit([], raw: '{"email":"'.str_repeat('a', 5000).'@x.com"}')->assertStatus(413);
        $this->submit([], raw: 'not json')->assertStatus(422);
        $this->submit(['email' => 'a@example.com', 'consent' => true], origin: 'https://evil.example')->assertStatus(403);
        $this->submit(['email' => 'a@example.com', 'consent' => true], origin: null)->assertStatus(403);
        $this->submit(['email' => 'a@example.com'], publicId: 'lf_'.str_repeat('a', 26))->assertNotFound();

        [, $inactive] = $this->form($this->tenant, active: false);
        $this->submit(['email' => 'a@example.com', 'consent' => true], publicId: $inactive)->assertNotFound();

        // The body cannot choose the tenant: another tenant's form id writes only there.
        [$other] = $this->createTenant('other');
        [, $otherPublic] = $this->form($other);
        $this->submit(['email' => 'spoof@example.com', 'consent' => true, 'tenant_id' => $this->tenant->id, 'tenant' => 'shop'], publicId: $otherPublic)->assertStatus(202);
        $this->assertSame(0, $this->rowCount('lead_submissions'));
        $this->assertSame(1, $this->inTenant($other, fn () => DB::table('lead_submissions')->count()));
        $this->assertSame(0, $this->inTenant($other, fn () => DB::table('lead_forms')->where('tenant_id', $this->tenant->id)->count()));
        $this->assertSame(0, DB::table('lead_forms')->count(), 'No rows without a tenant context.');
    }

    public function test_rate_limits_answer_429_and_are_counted(): void
    {
        $codes = [];
        foreach (range(1, 12) as $i) {
            $codes[] = $this->call('POST', "/public/v1/forms/{$this->publicId}/subscribe", [], [], [],
                ['HTTP_ORIGIN' => self::ORIGIN, 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '198.51.100.7'], (string) json_encode(['email' => "r{$i}@example.com", 'consent' => true]))->status();
        }
        $this->assertSame(array_merge(array_fill(0, 10, 202), [429, 429]), $codes);
        $this->assertSame(2, (int) $this->inTenant($this->tenant, fn () => DB::table('lead_metrics_daily')->value('rate_limited')));
    }

    public function test_identity_rules_link_safely_or_keep_leads_apart(): void
    {
        $existing = $this->shopper($this->tenant, 'kayitli@example.com', null);
        $this->inTenant($this->tenant, fn () => DB::table('customer_identities')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'customer_id' => $existing,
            'type' => 'email', 'value' => 'kayitli@example.com', 'source' => 'test']));
        $shared1 = $this->shopper($this->tenant, 'aile@example.com', null);
        $this->inTenant($this->tenant, function () {
            $id = (string) Str::uuid7();
            DB::table('customers')->insert(['id' => $id, 'tenant_id' => $this->tenant->id, 'email' => 'aile@example.com', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('recipient_customers')->insert(['tenant_id' => $this->tenant->id, 'recipient_id' => DB::table('consent_recipients')->where('address', 'aile@example.com')->value('id'),
                'customer_id' => $id, 'link_source' => 'test']);
        });

        foreach (['kayitli@example.com', 'aile@example.com', 'abc123@trendyolmail.com', 'yepyeni@example.com'] as $email) {
            $this->submit(['email' => $email, 'consent' => true])->assertStatus(202);
        }
        $rows = $this->inTenant($this->tenant, fn () => DB::table('lead_submissions')->orderBy('received_at')->orderBy('id')->get()->keyBy('outcome'));

        $this->assertTrue($this->inTenant($this->tenant, fn () => DB::table('lead_submissions')->where('outcome', 'accepted')->where('customer_id', $existing)->exists()), 'Linked to the customer owning the address.');
        $this->assertNull($rows['identity_conflict']->customer_id, 'No merge, no link on a shared address.');
        $this->assertNull($rows['marketplace_relay']->customer_id);
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => DB::table('customers')->whereRaw("attributes->>'source' = 'lead_form'")->count()), 'Only the unknown address became a lead customer.');
        $this->assertSame(2, $this->inTenant($this->tenant, fn () => DB::table('customer_events')->where('name', 'NEWSLETTER_SUBSCRIBED')->count()));
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => DB::table('consents')->where('recipient_id', DB::table('consent_recipients')->where('address', 'abc123@trendyolmail.com')->value('id'))->count()));
        $this->assertNotNull($shared1);
    }

    public function test_the_welcome_hook_enters_f5_but_plans_nothing_without_iys(): void
    {
        $this->inTenant($this->tenant, function () {
            $id = app(Automations::class)->create('Hoş geldin', ['type' => 'newsletter_subscribed', 'params' => []],
                [['key' => 'mail', 'type' => 'create_email_intent', 'params' => ['message_type' => 'marketing', 'on_ineligible' => 'continue']]], [], Actor::system());
            app(Automations::class)->publish($id, Actor::system());
        });
        $this->submit(['email' => 'hosgeldin@example.com', 'consent' => true])->assertStatus(202);
        $this->tick($this->tenant);
        $this->tick($this->tenant);

        $run = $this->runs($this->tenant)->sole();
        $this->assertSame(['exited', 'no_eligible_channel'], [$run->status, $run->exit_reason]);
        $this->assertCount(0, $this->intents($this->tenant));
        $this->assertSame('IYS_UNKNOWN', $this->inTenant($this->tenant, fn () => DB::table('automation_run_steps')->value('reason')));

        // Once İYS confirms ONAY for an address, the same flow plans the welcome e-mail (still no send).
        $this->shopper($this->tenant, 'onayli@example.com', ConsentStatus::Granted);
        $this->inTenant($this->tenant, fn () => DB::table('customer_identities')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id,
            'customer_id' => DB::table('customers')->where('email', 'onayli@example.com')->value('id'), 'type' => 'email', 'value' => 'onayli@example.com', 'source' => 'test']));
        $this->submit(['email' => 'onayli@example.com', 'consent' => true])->assertStatus(202);
        $this->tick($this->tenant);
        $this->assertSame(['email', 'marketing'], [$this->intents($this->tenant)->sole()->channel, $this->intents($this->tenant)->sole()->message_type]);
    }

    public function test_double_opt_in_is_store_side_pending_not_iys(): void
    {
        [, $public] = $this->form($this->tenant, doubleOptIn: true);
        $this->submit(['email' => 'onay@example.com', 'consent' => true], publicId: $public)->assertStatus(202);

        $s = $this->inTenant($this->tenant, fn () => DB::table('lead_submissions')->sole());
        $this->assertSame('pending', $s->opt_in_status);
        $this->assertNotNull($s->opt_in_expires_at);
        $this->assertSame('PENDING', $this->inTenant($this->tenant, fn () => DB::table('consents')->value('status')));
    }

    public function test_cors_preflight_answers_only_allowed_origins(): void
    {
        $ok = $this->call('OPTIONS', "/public/v1/forms/{$this->publicId}/subscribe", [], [], [], ['HTTP_ORIGIN' => self::ORIGIN]);
        $this->assertSame(self::ORIGIN, $ok->headers->get('Access-Control-Allow-Origin'));
        $bad = $this->call('OPTIONS', "/public/v1/forms/{$this->publicId}/subscribe", [], [], [], ['HTTP_ORIGIN' => 'https://evil.example']);
        $this->assertNull($bad->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_lead_report_is_aggregate_only(): void
    {
        $this->submit(['email' => 'a1@example.com', 'consent' => true, 'utm_source' => 'popup_test'])->assertStatus(202);
        $this->submit(['email' => 'a2@example.com', 'consent' => false])->assertStatus(202);
        $this->submit(['email' => 'bad', 'consent' => true])->assertStatus(422);

        $this->assertSame(0, Artisan::call('lead:report', ['--tenant' => 'shop']));
        $report = json_decode(Artisan::output(), true);
        $this->assertSame([3, 2, 1, 1, 1, 1], [$report['counters']['submissions'], $report['counters']['accepted'], $report['counters']['invalid'], $report['consent_checked'],
            $report['consent_unchecked'], $report['pending_iys_onay']]);
        $this->assertSame(['popup_test' => 1], $report['by_utm_source']);
        $this->assertStringNotContainsString('@', Artisan::output());
    }

    public function test_cart_relink_dry_run_changes_nothing_and_the_run_is_idempotent(): void
    {
        $member = $this->shopper($this->tenant, 'uye@example.com', null);
        $this->inTenant($this->tenant, fn () => DB::table('customer_identities')->insert([
            ['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'customer_id' => $member, 'type' => 'commerce_member', 'value' => '155323', 'source' => 'test'],
            ['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'customer_id' => $member, 'type' => 'email', 'value' => 'uye@example.com', 'source' => 'test'],
        ]));
        $product = $this->product($this->tenant);
        $external = $this->inTenant($this->tenant, fn () => DB::table('products')->where('id', $product)->value('external_id'));
        $byMember = $this->cart($this->tenant, null, [$product]);
        $byEmail = $this->cart($this->tenant, null, [$product]);
        $anonymous = $this->cart($this->tenant, null, [$product]);
        $collision = $this->cart($this->tenant, null, [$product]);
        $this->inTenant($this->tenant, function () use ($byMember, $byEmail, $collision, $external) {
            DB::table('cart_items')->update(['product_id' => null, 'product_external_id' => $external]);
            DB::table('carts')->where('id', $byMember)->update(['external_member_id' => '155323']);
            DB::table('carts')->where('id', $byEmail)->update(['email' => 'UYE@example.com']);
            DB::table('carts')->where('id', $collision)->update(['email' => 'cakisma@example.com']);
            $other = $this->shopper($this->tenant, 'cakisma@example.com', null);
            DB::table('customer_identities')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'customer_id' => $other, 'type' => 'email', 'value' => 'cakisma@example.com', 'source' => 't']);
            DB::table('customer_merges')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'primary_customer_id' => $other, 'secondary_customer_id' => null,
                'identity_type' => 'email', 'identity_hash' => hash('sha256', 'cakisma@example.com'), 'status' => 'pending_review', 'reason' => 'test']);
        });

        $before = $this->inTenant($this->tenant, fn () => DB::table('carts')->whereNotNull('customer_id')->count() + DB::table('cart_items')->whereNotNull('product_id')->count());
        $dry = $this->inTenant($this->tenant, fn () => app(CommerceRelink::class)->dryRun());
        $this->assertSame($before, $this->inTenant($this->tenant, fn () => DB::table('carts')->whereNotNull('customer_id')->count() + DB::table('cart_items')->whereNotNull('product_id')->count()));
        $this->assertSame([4, 0, 4], [$dry['cart_items']['total'], $dry['cart_items']['linked'], $dry['cart_items']['by_product']]);
        $this->assertSame([1, 1, 1], [$dry['carts']['member_recoverable'], $dry['carts']['email_recoverable'], $dry['carts']['email_collision']]);

        $this->assertSame(0, Artisan::call('commerce:cart-relink', ['--tenant' => 'shop']));
        $result = json_decode(Artisan::output(), true);
        $this->assertSame(['cart_items' => 4, 'order_items' => 0, 'carts_by_member' => 1, 'carts_by_email' => 1], $result['updated']);
        $linked = $this->inTenant($this->tenant, fn () => DB::table('carts')->pluck('customer_id', 'id'));
        $this->assertSame([$member, $member, null, null], [$linked[$byMember], $linked[$byEmail], $linked[$anonymous], $linked[$collision]]);

        $this->assertSame(0, Artisan::call('commerce:cart-relink', ['--tenant' => 'shop']));
        $this->assertSame(['cart_items' => 0, 'order_items' => 0, 'carts_by_member' => 0, 'carts_by_email' => 0], json_decode(Artisan::output(), true)['updated']);
        Http::assertNothingSent();
    }
}
