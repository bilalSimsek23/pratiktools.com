<?php

namespace Tests\Feature\Email;

use App\Audit\Actor;
use App\Consent\ConsentStatus;
use App\Email\CampaignPreparation;
use App\Email\EmailMessageType;
use App\Email\Templates\EmailTemplates;
use App\Email\Templates\TemplateError;
use App\Email\Templates\TemplateRenderer;
use App\Jobs\SendEmail;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithSes;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class EmailTemplatesTest extends TestCase
{
    use InteractsWithSes, InteractsWithTenants;

    private Tenant $tenant;

    private TenantIntegration $ses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSes();
        [$this->tenant] = $this->createTenant('shop');
        $this->ses = $this->connectSes($this->tenant);
        Queue::fake([SendEmail::class]);
    }

    private function reason(callable $fn): ?string
    {
        try {
            $fn();
        } catch (TemplateError $e) {
            return $e->reason;
        }

        return null;
    }

    private function marketingVersion(string $subject = 'Merhaba {{ customer.first_name | default: "Değerli müşterimiz" }}'): string
    {
        return $this->inTenant($this->tenant, function () use ($subject) {
            $templates = app(EmailTemplates::class);
            $id = $templates->create('Kasım kampanyası', EmailMessageType::Marketing, Actor::system());

            $version = $templates->addVersion($id, $subject,
                '<p>Merhaba {{ customer.first_name | default: "Değerli müşterimiz" }}, kod: {{ coupon.code }}</p><a href="{{ unsubscribe_url }}">Çık</a>',
                "Merhaba {{ customer.first_name | default: \"Değerli müşterimiz\" }}\nKod: {{ coupon.code }}\nÇıkış: {{ unsubscribe_url }}", Actor::system());
            $templates->publish($version, Actor::system());

            return $version;
        });
    }

    public function test_values_are_escaped_for_their_context(): void
    {
        $r = app(TemplateRenderer::class);
        $evil = '<script>alert("x")</script> & "Ayşe"';

        $this->assertSame('<p>&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &quot;Ayşe&quot;</p>', $r->render('<p>{{ customer.first_name }}</p>', ['customer.first_name' => $evil], TemplateRenderer::HTML));
        $this->assertSame('Merhaba Ayşe Bcc: x@y.z', $r->render('Merhaba {{ customer.first_name }}', ['customer.first_name' => "Ayşe\r\nBcc: x@y.z"], TemplateRenderer::SUBJECT));
        $this->assertSame('Kod: <b>A1</b>', $r->render('Kod: {{ coupon.code }}', ['coupon.code' => "<b>A1</b>\x07"], TemplateRenderer::TEXT));
        $this->assertSame('INVALID_URL_VARIABLE', $this->reason(fn () => $r->render('{{ product.url }}', ['product.url' => 'javascript:alert(1)'], TemplateRenderer::HTML)));
        // Template text is data: nothing in it is evaluated.
        $this->assertSame('<?php echo 1; ?> 7', $r->render('<?php echo 1; ?> {{ product.price }}', ['product.price' => '7'], TemplateRenderer::TEXT));
    }

    public function test_missing_unknown_and_unsupported_constructs_are_refused(): void
    {
        $r = app(TemplateRenderer::class);

        $this->assertSame('MISSING_VARIABLE', $this->reason(fn () => $r->render('Merhaba {{ customer.first_name }}', [], TemplateRenderer::TEXT)));
        $this->assertSame('Merhaba dostum', $r->render('Merhaba {{ customer.first_name | default: "dostum" }}', ['customer.first_name' => ''], TemplateRenderer::TEXT));
        $this->assertSame('UNKNOWN_VARIABLE', $this->reason(fn () => $r->variables('{{ customer.password }}')));
        foreach (['{{ customer.first_name | raw }}', '{{ app.key }}{{', '{{ 1 + 1 }}', '{{{ customer.first_name }}}', '{{ customer.first_name | default: "x" }} }}'] as $bad) {
            $this->assertContains($this->reason(fn () => $r->variables($bad)), ['INVALID_SYNTAX', 'UNKNOWN_VARIABLE'], $bad);
        }

        $this->inTenant($this->tenant, function () {
            $templates = app(EmailTemplates::class);
            $id = $templates->create('Eksik', EmailMessageType::Marketing, Actor::system());
            $this->assertSame('UNSUBSCRIBE_MISSING', $this->reason(fn () => $templates->addVersion($id, 'Konu', '<p>x</p>', 'x {{ unsubscribe_url }}', Actor::system())));
            $this->assertSame('UNKNOWN_VARIABLE', $this->reason(fn () => $templates->addVersion($id, 'Konu {{ customer.email }}', null, 'x {{ unsubscribe_url }}', Actor::system())));
            $this->assertSame(0, DB::table('email_template_versions')->count());
        });
    }

    public function test_templates_of_another_tenant_cannot_be_read_or_used(): void
    {
        $version = $this->marketingVersion();
        [$other] = $this->createTenant('other');

        $this->assertSame('NOT_FOUND', $this->reason(fn () => $this->inTenant($other, fn () => app(EmailTemplates::class)->version($version))));
        $this->assertSame(0, $this->inTenant($other, fn () => DB::table('email_template_versions')->count() + DB::table('email_templates')->count()));
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => DB::table('email_template_versions')->count()));
        $this->assertSame(0, DB::table('email_template_versions')->count(), 'No rows without a tenant context.');
    }

    public function test_campaign_preparation_filters_by_consent_and_is_idempotent(): void
    {
        $sender = $this->senderIdentity($this->tenant, $this->ses);
        $version = $this->marketingVersion();
        $allowed = $this->emailRecipient($this->tenant, 'ayse@example.com', ConsentStatus::Granted);
        $unknown = $this->emailRecipient($this->tenant, 'mehmet@example.com', ConsentStatus::Unknown);
        $prep = app(CampaignPreparation::class);

        $snapshot = $this->inTenant($this->tenant, fn () => $prep->snapshot([$allowed, $unknown], null, ['segment' => 'test'], Actor::system()));
        $this->assertSame(2, $this->inTenant($this->tenant, fn () => (int) DB::table('email_audience_snapshots')->value('member_count')));

        $first = $this->inTenant($this->tenant, fn () => $prep->generate($snapshot, $version, $this->ses, $sender, ['coupon.code' => 'KASIM10'], Actor::system()));
        $this->assertSame(['queued' => 1, 'duplicates' => 0, 'excluded' => ['IYS_UNKNOWN' => 1]], $first);
        $again = $this->inTenant($this->tenant, fn () => $prep->generate($snapshot, $version, $this->ses, $sender, ['coupon.code' => 'KASIM10'], Actor::system()));
        $this->assertSame(1, $again['duplicates']);
        $this->assertSame(0, $again['queued']);

        $message = $this->inTenant($this->tenant, fn () => DB::table('email_messages')->first());
        $this->assertSame('marketing', $message->message_type);
        $this->assertSame('Merhaba Değerli müşterimiz', $message->subject);
        $this->assertStringContainsString('https://go.pratiktools.test/u/', $message->text_body);
        $unsubscribe = json_decode($message->headers, true)['List-Unsubscribe'];
        $this->assertStringContainsString(trim($unsubscribe, '<>'), $message->text_body, 'Header and body carry the same link.');
        $this->assertSame([], $this->sesRequests, 'Preparing a campaign never sends.');

        // The audience stays frozen even if recipients change later.
        $this->emailRecipient($this->tenant, 'yeni@example.com', ConsentStatus::Granted);
        $this->assertSame(2, $this->inTenant($this->tenant, fn () => DB::table('email_audience_members')->where('snapshot_id', $snapshot)->count()));
    }
}
