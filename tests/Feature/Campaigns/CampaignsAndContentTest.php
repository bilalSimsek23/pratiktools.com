<?php

namespace Tests\Feature\Campaigns;

use App\Audit\Actor;
use App\Automation\Automations;
use App\Campaigns\CampaignAudience;
use App\Campaigns\CampaignDryRun;
use App\Campaigns\CampaignError;
use App\Campaigns\CampaignReadiness;
use App\Campaigns\Campaigns;
use App\Campaigns\CampaignStatus;
use App\Connectors\OneSignal\OneSignalClientFactory;
use App\Connectors\OneSignal\OneSignalPushClient;
use App\Connectors\Ses\SesClientFactory;
use App\Connectors\Ses\SesEmailProvider;
use App\Consent\ConsentChannel;
use App\Consent\ConsentStatus;
use App\Consent\SuppressionService;
use App\Consent\SuppressionSource;
use App\Email\EmailMessageType;
use App\Email\EmailProviderResolver;
use App\Email\Templates\EmailHtmlPolicy;
use App\Email\Templates\EmailTemplates;
use App\Email\Templates\TemplateError;
use App\Jobs\SendEmail;
use App\Jobs\SendPush;
use App\Leads\LeadForms;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Segments\Segments;
use App\Tenancy\TenantRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\InteractsWithAutomation;
use Tests\Concerns\InteractsWithSes;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class CampaignsAndContentTest extends TestCase
{
    use InteractsWithAutomation, InteractsWithSes, InteractsWithTenants;

    private const HTML = '<html><body><p>Merhaba {{ customer.first_name | default: "Değerli müşterimiz" }}</p><a href="{{ unsubscribe_url }}">Abonelikten çık</a></body></html>';

    private const TEXT = "Merhaba {{ customer.first_name | default: \"Değerli müşterimiz\" }}\nÇıkış: {{ unsubscribe_url }}";

    private Tenant $tenant;

    private TenantIntegration $ses;

    private string $sender;

    private string $segment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSes();
        [$this->tenant] = $this->createTenant('shop');
        $this->ses = $this->connectSes($this->tenant);
        $this->sender = $this->senderIdentity($this->tenant, $this->ses);
        $this->iysSynced($this->tenant);
        foreach (['a@example.com' => ConsentStatus::Granted, 'b@example.com' => ConsentStatus::Granted, 'c@example.com' => ConsentStatus::Unknown] as $email => $iys) {
            $this->stats($this->tenant, $this->shopper($this->tenant, $email, $iys), now()->subDays(5)->toImmutable());
        }
        $this->segment = $this->inTenant($this->tenant, fn () => app(Segments::class)->create('Son 30 gün', 'dynamic',
            ['all' => [['field' => 'last_order_at', 'op' => 'within_last_days', 'value' => 30]]], Actor::system()));
    }

    private function version(string $html = self::HTML, bool $publish = true, string $type = 'marketing'): string
    {
        return $this->inTenant($this->tenant, function () use ($html, $publish, $type) {
            $t = app(EmailTemplates::class);
            $id = $t->create('T'.Str::random(6), EmailMessageType::from($type), Actor::system());
            $v = $t->addVersion($id, 'Kasım {{ campaign.name }}', $html, self::TEXT, Actor::system(), 'Size özel fırsatlar');
            if ($publish) {
                $t->publish($v, Actor::system());
            }

            return $v;
        });
    }

    private function campaign(?string $version = null, ?string $sender = null): string
    {
        $version ??= $this->version();

        return $this->inTenant($this->tenant, fn () => app(Campaigns::class)->create(['name' => 'Kasım '.Str::random(5), 'type' => 'newsletter', 'segment_id' => $this->segment,
            'template_version_id' => $version, 'integration_id' => $this->ses->id, 'sender_identity_id' => $sender ?? $this->sender], Actor::system()));
    }

    private function readiness(string $campaign): array
    {
        return $this->inTenant($this->tenant, fn () => app(CampaignReadiness::class)->evaluate(app(Campaigns::class)->find($campaign)));
    }

    private function checkStatus(array $readiness, string $check): string
    {
        return collect($readiness['checks'])->firstWhere('check', $check)['status'];
    }

    private function templateError(callable $fn): ?string
    {
        try {
            $fn();
        } catch (TemplateError $e) {
            return $e->reason;
        }

        return null;
    }

    public function test_published_versions_are_immutable_and_drafts_cannot_be_used(): void
    {
        $draft = $this->version(publish: false);
        $this->assertSame('CONTENT_NOT_PUBLISHED', collect($this->readiness($this->campaign($draft))['checks'])->firstWhere('check', 'CONTENT')['reason']);

        $published = $this->version();
        $this->expectException(QueryException::class);
        $this->inTenant($this->tenant, fn () => DB::table('email_template_versions')->where('id', $published)->update(['html_template' => '<p>changed</p>']));
    }

    public function test_unsafe_html_and_variables_are_refused_and_sanitized(): void
    {
        foreach ([
            '<script>alert(1)</script>' => 'TAG_SCRIPT', '<iframe src="https://x.test"></iframe>' => 'TAG_IFRAME', '<form action="https://x.test"></form>' => 'TAG_FORM',
            '<a href="javascript:alert(1)">x</a>' => 'UNSAFE_URL', '<img src="x.png" onerror="alert(1)">' => 'EVENT_HANDLER', '<a href="http://x.test">x</a>' => 'INSECURE_URL',
            '<p style="width:expression(alert(1))">x</p>' => 'UNSAFE_CSS', '<meta http-equiv="refresh" content="0;url=https://x.test">' => 'META_HTTP_EQUIV',
            '<p style="background:url(http://x.test/a.png)">x</p>' => 'UNSAFE_CSS', '<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>' => 'UNSAFE_URL',
        ] as $fragment => $code) {
            $this->assertContains($code, app(EmailHtmlPolicy::class)->issues($fragment), $fragment);
            $this->assertSame('UNSAFE_HTML', $this->templateError(fn () => $this->version('<html><body>'.$fragment.'<a href="{{ unsubscribe_url }}">x</a></body></html>', false)), $fragment);
        }
        $this->assertSame('UNKNOWN_VARIABLE', $this->templateError(fn () => $this->version('<p>{{ customer.password }}</p><a href="{{ unsubscribe_url }}">x</a>', false)));
        $this->assertSame('INVALID_SYNTAX', $this->templateError(fn () => $this->version('<p>{{ customer.first_name | raw }}</p><a href="{{ unsubscribe_url }}">x</a>', false)));
        $this->assertSame('UNSUBSCRIBE_MISSING', $this->templateError(fn () => $this->version('<p>Merhaba</p>', false)));

        $clean = app(EmailHtmlPolicy::class)->sanitize('<p onclick="x()">a<script>evil()</script><a href="javascript:x">b</a><img src="https://ok.test/i.png" onerror="y"></p>');
        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('javascript', $clean);
        $this->assertStringContainsString('https://ok.test/i.png', $clean);
    }

    public function test_preview_and_send_use_the_same_pipeline_without_any_request(): void
    {
        $version = $this->version('<html><body><p>Merhaba {{ customer.first_name }}</p><a href="{{ unsubscribe_url }}">Çık</a></body></html>');
        [$owner] = [User::query()->where('email', 'owner@shop.test')->firstOrFail()];
        $preview = $this->api('GET', "/api/v1/email-template-versions/{$version}/preview", $this->tokenFor($owner, $this->tenant))->assertOk()->json('data');

        $this->assertSame('Kasım Örnek kampanya', $preview['subject']);
        $this->assertSame('Size özel fırsatlar', $preview['preheader']);
        $this->assertStringContainsString('display:none', $preview['html']);
        $this->assertStringContainsString('Merhaba Ayşe', $preview['text']);
        $this->assertSame(['desktop' => 640, 'mobile' => 375], $preview['viewports']);
        $direct = $this->inTenant($this->tenant, fn () => app(EmailTemplates::class)->render(app(EmailTemplates::class)->version($version), EmailTemplates::sampleValues()));
        $this->assertSame($direct['html'], $preview['html']);
        $this->assertSame([], $this->sesRequests);
    }

    public function test_readiness_explains_every_blocker_and_dispatch_is_never_available(): void
    {
        $campaign = $this->campaign();
        $r = $this->readiness($campaign);

        foreach (['CONTENT', 'AUDIENCE', 'SENDER', 'DKIM', 'UNSUBSCRIBE', 'IYS'] as $check) {
            $this->assertSame('PASS', $this->checkStatus($r, $check), $check);
        }
        $this->assertSame('WARN', $this->checkStatus($r, 'MAIL_FROM'), 'A missing custom MAIL FROM never blocks.');
        $this->assertSame(['SES_SANDBOX', 'SENDING_MODE_OFF', 'DISPATCH_NOT_AVAILABLE'], $r['reason_codes']);
        $this->assertTrue($r['can_prepare']);
        $this->assertFalse($r['sendable']);

        // Even with every external check green, F6 has no dispatcher.
        config(['email.sending_mode' => 'live']);
        $this->inTenant($this->tenant, fn () => DB::table('tenant_settings')->update(['sending_mode' => 'live']));
        $this->inTenant($this->tenant, fn () => $this->ses->forceFill(['settings' => ['account' => ['production_access' => true] + $this->ses->settings['account']] + $this->ses->settings])->save());
        $this->assertSame(['DISPATCH_NOT_AVAILABLE'], $this->readiness($campaign)['reason_codes']);
    }

    public function test_missing_unsubscribe_key_blocks_ready_but_not_drafting(): void
    {
        config(['email.unsubscribe.key' => null]);
        $campaign = $this->campaign();

        $r = $this->readiness($campaign);
        $this->assertSame('BLOCKED', $this->checkStatus($r, 'UNSUBSCRIBE'));
        $this->assertFalse($r['can_prepare']);
        try {
            $this->inTenant($this->tenant, fn () => app(Campaigns::class)->transition($campaign, CampaignStatus::Ready, Actor::system()));
            $this->fail('NOT_READY expected');
        } catch (CampaignError $e) {
            $this->assertSame('NOT_READY', $e->reason);
            $this->assertContains('EMAIL_UNSUBSCRIBE_KEY_MISSING', $e->codes);
        }
        $this->assertSame('draft', $this->inTenant($this->tenant, fn () => DB::table('campaigns')->value('status')));
    }

    public function test_unverified_or_foreign_senders_block_readiness(): void
    {
        $pending = $this->inTenant($this->tenant, function () {
            $id = (string) Str::uuid7();
            DB::table('email_sender_identities')->insert(['id' => $id, 'tenant_id' => $this->tenant->id, 'integration_id' => $this->ses->id, 'provider' => 'amazon_ses',
                'domain' => 'new.example.com', 'from_email' => 'x@new.example.com', 'provider_identity_id' => 'new.example.com', 'verification_status' => 'pending', 'dkim_status' => 'pending',
                'created_at' => now(), 'updated_at' => now()]);

            return $id;
        });
        $r = $this->readiness($this->campaign(sender: $pending));
        $this->assertSame('BLOCKED', $this->checkStatus($r, 'SENDER'));
        $this->assertSame('BLOCKED', $this->checkStatus($r, 'DKIM'));
        $this->assertFalse($r['can_prepare']);

        [$other] = $this->createTenant('other');
        $foreign = $this->senderIdentity($other, $this->connectSes($other));
        $this->expectException(CampaignError::class);
        $this->campaign(sender: $foreign);
    }

    public function test_iys_unavailable_shows_zero_eligible_with_an_explanation(): void
    {
        $this->inTenant($this->tenant, fn () => DB::table('integration_sync_cursors')->update(['last_success_at' => null]));
        $campaign = $this->campaign();
        $report = $this->inTenant($this->tenant, fn () => app(CampaignAudience::class)->report(app(Campaigns::class)->find($campaign)));

        $this->assertSame(3, $report['raw_audience']);
        $this->assertSame(3, $report['valid_identity']);
        $this->assertSame(0, $report['currently_eligible']);
        $this->assertSame(3, $report['iys_unknown_or_stale']);
        $this->assertSame('Marketing sending is blocked because IYS consent could not be verified.', $report['explanation']);
        $r = $this->readiness($campaign);
        $this->assertContains('IYS_UNAVAILABLE', $r['reason_codes']);
        $this->assertContains('NO_ELIGIBLE_RECIPIENTS', $r['reason_codes']);
        $this->assertStringNotContainsString('@', json_encode($report));
    }

    public function test_frozen_audience_and_content_never_change_but_eligibility_is_rechecked(): void
    {
        $campaign = $this->campaign();
        $this->inTenant($this->tenant, fn () => app(Campaigns::class)->transition($campaign, CampaignStatus::Ready, Actor::system()));
        $row = $this->inTenant($this->tenant, fn () => app(Campaigns::class)->find($campaign));
        $this->assertNotNull($row->frozen_at);
        $snapshot = $row->segment_snapshot_id;

        // Retried freeze: same snapshot, no duplicate members.
        $this->assertSame($snapshot, $this->inTenant($this->tenant, fn () => app(Segments::class)->snapshot($this->segment, Actor::system(), "campaign:{$campaign}")));
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => DB::table('segment_snapshots')->count()));
        $this->assertSame(3, $this->inTenant($this->tenant, fn () => DB::table('segment_snapshot_members')->count()));

        // New members of the segment do not join the frozen audience.
        $this->stats($this->tenant, $this->shopper($this->tenant, 'later@example.com'), now()->subDay()->toImmutable());
        $before = $this->inTenant($this->tenant, fn () => app(CampaignAudience::class)->report(app(Campaigns::class)->find($campaign)));
        $this->assertSame(3, $before['raw_audience']);
        $this->assertSame(2, $before['currently_eligible']);

        // After the freeze: İYS RET, a suppression and the cap still apply.
        $this->iys($this->tenant, 'a@example.com', ConsentStatus::Revoked);
        $b = $this->inTenant($this->tenant, fn () => (string) DB::table('consent_recipients')->where('address', 'b@example.com')->value('id'));
        $this->inTenant($this->tenant, fn () => app(SuppressionService::class)->suppress($b, SuppressionSource::Manual, ConsentChannel::Email, 'test', Actor::system()));
        $after = $this->inTenant($this->tenant, fn () => app(CampaignAudience::class)->report(app(Campaigns::class)->find($campaign)));
        $this->assertSame([0, 1, 1], [$after['currently_eligible'], $after['suppressed'], (int) $after['reasons']['IYS_REVOKED']]);

        try {
            $this->inTenant($this->tenant, fn () => DB::table('campaigns')->where('id', $campaign)->update(['template_version_id' => $this->version()]));
            $this->fail('A frozen campaign must keep its content.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('frozen', $e->getMessage());
        }
        $this->expectException(CampaignError::class);
        $this->inTenant($this->tenant, fn () => app(Campaigns::class)->update($campaign, ['name' => 'x', 'type' => 'newsletter', 'segment_id' => $this->segment], Actor::system()));
    }

    public function test_the_frequency_cap_applies_to_the_audience(): void
    {
        $campaign = $this->campaign();
        $this->inTenant($this->tenant, function () {
            $customer = (string) DB::table('customers')->where('email', 'a@example.com')->value('id');
            $automation = app(Automations::class)->create('x', ['type' => 'order_created', 'params' => []], [['key' => 'e', 'type' => 'exit']], [], Actor::system());
            $version = DB::table('automation_versions')->value('id');
            $run = (string) Str::uuid7();
            DB::table('automation_runs')->insert(['id' => $run, 'tenant_id' => $this->tenant->id, 'automation_id' => $automation, 'version_id' => $version, 'trigger_type' => 'order_created',
                'trigger_ref' => 'r', 'idempotency_key' => str_repeat('b', 64), 'scope_key' => 'customer:'.$customer, 'customer_id' => $customer, 'status' => 'completed', 'triggered_at' => now(),
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('automation_planned_actions')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'run_id' => $run, 'automation_id' => $automation,
                'version_id' => $version, 'step_key' => 'e', 'channel' => 'email', 'message_type' => 'marketing', 'customer_id' => $customer,
                'recipient_ref' => DB::table('consent_recipients')->where('address', 'a@example.com')->value('id'), 'idempotency_key' => str_repeat('c', 64), 'created_at' => now()]);
        });
        $report = $this->inTenant($this->tenant, fn () => app(CampaignAudience::class)->report(app(Campaigns::class)->find($campaign)));
        $this->assertSame([2, 1, 1], [$report['consent_allowed'], $report['frequency_capped'], $report['currently_eligible']]);
    }

    public function test_campaign_dry_run_is_side_effect_free(): void
    {
        $campaign = $this->campaign();
        $count = fn () => $this->inTenant($this->tenant, fn () => collect(['campaigns', 'segment_snapshots', 'segment_snapshot_members', 'email_messages', 'email_audience_snapshots',
            'automation_planned_actions', 'consents', 'suppressions', 'audit_logs'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all());
        $before = $count();
        $report = $this->inTenant($this->tenant, fn () => app(CampaignDryRun::class)->run($campaign, Actor::system()));
        $after = $count();

        $this->assertEquals(['audit_logs' => $before['audit_logs'] + 1] + $before, $after);
        $this->assertSame([3, 2, 1, 2, 0, 0], [$report['raw_audience'], $report['consent_allowed'], $report['iys_unknown_or_stale'], $report['planned_email'], $report['provider_calls'], $report['send_jobs']]);
        $this->assertFalse($report['sender_blocked']);
        $this->assertSame([], $this->sesRequests);
    }

    public function test_tenants_and_roles_cannot_reach_campaigns_or_content_they_should_not(): void
    {
        $campaign = $this->campaign();
        $draft = $this->version(publish: false);
        [$other, $otherOwner] = $this->createTenant('other');
        $otherToken = $this->tokenFor($otherOwner, $other);

        $this->api('GET', "/api/v1/campaigns/{$campaign}", $otherToken)->assertNotFound();
        $this->api('POST', "/api/v1/campaigns/{$campaign}/transition", $otherToken, ['to' => 'ready'])->assertNotFound();
        $this->api('GET', "/api/v1/email-template-versions/{$draft}/preview", $otherToken)->assertNotFound();
        $this->assertSame([], $this->api('GET', '/api/v1/campaigns', $otherToken)->json('data'));
        $this->assertSame(0, $this->inTenant($other, fn () => DB::table('campaigns')->count() + DB::table('email_templates')->count()));

        $marketer = $this->tokenFor($this->addMember($this->tenant, TenantRole::Marketer), $this->tenant);
        $this->api('POST', "/api/v1/email-template-versions/{$draft}/publish", $marketer)->assertForbidden();
        $this->api('POST', "/api/v1/campaigns/{$campaign}/transition", $marketer, ['to' => 'ready'])->assertForbidden();
        $this->api('GET', "/api/v1/campaigns/{$campaign}/audience", $marketer)->assertOk();
        $viewer = $this->tokenFor($this->addMember($this->tenant, TenantRole::Viewer), $this->tenant);
        $this->api('GET', '/api/v1/campaigns', $viewer)->assertForbidden();

        $owner = $this->tokenFor(User::query()->where('email', 'owner@shop.test')->firstOrFail(), $this->tenant);
        $this->api('POST', "/api/v1/email-template-versions/{$draft}/publish", $owner)->assertOk();
        $this->api('POST', "/api/v1/campaigns/{$campaign}/transition", $owner, ['to' => 'ready'])->assertOk()->assertJsonPath('data.can_prepare', true);
        $this->api('POST', "/api/v1/campaigns/{$campaign}/transition", $owner, ['to' => 'completed'])->assertUnprocessable();
        $this->api('POST', "/api/v1/campaigns/{$campaign}/transition", $owner, ['to' => 'scheduled', 'scheduled_at' => now()->addDay()->toIso8601String()])->assertOk();
        $this->assertSame('scheduled', $this->inTenant($this->tenant, fn () => DB::table('campaigns')->where('id', $campaign)->value('status')));
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => DB::table('email_messages')->count()), 'Scheduled is not a send.');
    }

    public function test_no_provider_or_send_job_is_reachable_from_f6_flows(): void
    {
        $calls = 0;
        $this->app->instance(SesClientFactory::class, new class($calls) extends SesClientFactory
        {
            public function __construct(private int &$calls) {}

            public function make(TenantIntegration $integration, string $purpose, ?Actor $actor = null): SesEmailProvider
            {
                $this->calls++;
                throw new RuntimeException('SES reached from F6');
            }
        });
        $this->app->instance(OneSignalClientFactory::class, new class($calls) extends OneSignalClientFactory
        {
            public function __construct(private int &$calls) {}

            public function push(TenantIntegration $integration, ?Actor $actor = null): OneSignalPushClient
            {
                $this->calls++;
                throw new RuntimeException('OneSignal reached from F6');
            }
        });
        $this->app->forgetInstance(EmailProviderResolver::class);
        Queue::fake([SendEmail::class, SendPush::class]);
        config(['email.sending_mode' => 'live', 'push.sending_mode' => 'live']);

        $campaign = $this->campaign();
        $this->inTenant($this->tenant, function () use ($campaign) {
            app(CampaignAudience::class)->report(app(Campaigns::class)->find($campaign));
            app(CampaignDryRun::class)->run($campaign, Actor::system());
            app(Campaigns::class)->transition($campaign, CampaignStatus::Ready, Actor::system());
            $t = app(EmailTemplates::class);
            $t->render($t->version(DB::table('campaigns')->value('template_version_id')), EmailTemplates::sampleValues());
            $automation = app(Automations::class)->create('Hoş geldin', ['type' => 'newsletter_subscribed', 'params' => []],
                [['key' => 'mail', 'type' => 'create_email_intent', 'params' => ['message_type' => 'marketing', 'on_ineligible' => 'continue']]], [], Actor::system());
            app(Automations::class)->publish($automation, Actor::system());
            $form = app(LeadForms::class)->create('Footer', 'footer', 'Kampanyalardan haberdar olmak istiyorum.', 'Teşekkürler!', ['https://www.example.com'], Actor::system());
            app(LeadForms::class)->setActive($form, true, Actor::system());
        });
        $publicId = $this->inTenant($this->tenant, fn () => (string) DB::table('lead_forms')->value('public_id'));
        $this->call('POST', "/public/v1/forms/{$publicId}/subscribe", [], [], [], ['HTTP_ORIGIN' => 'https://www.example.com', 'CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['email' => 'yeni@example.com', 'consent' => true]))->assertStatus(202);
        $this->tick($this->tenant);

        $this->assertSame(0, $calls);
        Queue::assertNotPushed(SendEmail::class);
        Queue::assertNotPushed(SendPush::class);
        $this->assertSame([], $this->sesRequests);
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => DB::table('email_messages')->count() + DB::table('push_send_requests')->count()));
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => DB::table('automation_runs')->count()), 'The welcome hook started a run…');
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => DB::table('automation_planned_actions')->count()), '…but İYS has no ONAY for the lead, so no intent.');
    }
}
