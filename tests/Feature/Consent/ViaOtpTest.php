<?php

namespace Tests\Feature\Consent;

use App\Audit\Actor;
use App\Consent\ConsentChannel;
use App\Consent\ConsentStrategy;
use App\Consent\ConsentStrategyMode;
use App\Consent\EligibilityReason;
use App\Consent\Reconciliation\IysReconciliationImporter;
use App\Consent\Reconciliation\ReconciliationFile;
use App\Consent\SendEligibility;
use App\Consent\Via\ViaConfigurations;
use App\Consent\Via\ViaConsentProvider;
use App\Consent\Via\ViaConsentRequests;
use App\Consent\Via\ViaError;
use App\Consent\Via\ViaFormStatus;
use App\Consent\Via\ViaOtpConsentProvider;
use App\Consent\Via\ViaOtpTransport;
use App\Consent\Via\ViaOtpVerifyResult;
use App\Consent\Via\ViaStatus;
use App\Email\Unsubscribes;
use App\Email\UnsubscribeTokens;
use App\Http\Middleware\PanelRecentAuth;
use App\Http\Middleware\PanelTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Operations\ExternalBlockers;
use App\Tenancy\TenantRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithAutomation;
use Tests\Concerns\InteractsWithSes;
use Tests\Concerns\InteractsWithTenants;
use Tests\Support\FakeViaOtpTransport;
use Tests\Support\TestChangeReportParser;
use Tests\TestCase;

/**
 * İYS ViA OTP form model: form status, readiness, guarded OTP provider.
 * The Form ID below is a made-up test value.
 */
final class ViaOtpTest extends TestCase
{
    use InteractsWithAutomation, InteractsWithSes, InteractsWithTenants;

    private const FORM_ID = 'TESTFORM0001';

    private Tenant $tenant;

    private User $owner;

    private string $customer;

    private FakeViaOtpTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSes();
        Http::preventStrayRequests();
        [$this->tenant, $this->owner] = $this->createTenant('shop');
        $this->customer = $this->shopper($this->tenant, 'ayse@example.com', null);
        $this->transport = new FakeViaOtpTransport;
    }

    /**
     * The IGS form as recorded today: OTP, ETK, submitted for approval.
     */
    private function igsForm(?string $formId = self::FORM_ID, ?Tenant $tenant = null): void
    {
        $this->inTenant($tenant ?? $this->tenant, fn () => app(ViaConfigurations::class)->configure(array_filter(['iys_number' => '620874', 'brand' => 'IGS', 'package_status' => 'active',
            'form_type' => 'OTP', 'approval_type' => 'ETK', 'form_status' => 'pending_approval', 'form_id' => $formId]), Actor::console('test')));
    }

    private function approve(): void
    {
        $this->inTenant($this->tenant, fn () => app(ViaConfigurations::class)->setFormStatus(ViaFormStatus::Approved, Actor::console('test'), 'portal shows Onaylandı', portalConfirmed: true));
    }

    private function fakeTransport(): void
    {
        $this->app->instance(ViaOtpTransport::class, $this->transport);
    }

    private function viaStatus(): ViaStatus
    {
        return $this->inTenant($this->tenant, fn () => app(ViaConsentProvider::class)->status());
    }

    private function request(string $email = 'ayse@example.com'): string
    {
        return $this->inTenant($this->tenant, fn () => app(ViaConsentRequests::class)->open(
            (string) DB::table('consent_recipients')->where('address', $email)->value('id'), $this->customer, null, null));
    }

    private function otp(string $method, string ...$args): mixed
    {
        return $this->inTenant($this->tenant, fn () => app(ViaOtpConsentProvider::class)->{$method}(...$args));
    }

    private function refused(callable $fn): ?string
    {
        try {
            $fn();
        } catch (ViaError $e) {
            return $e->reason;
        }

        return null;
    }

    private function rows(string $table, ?callable $where = null): int
    {
        return $this->inTenant($this->tenant, fn () => ($where === null ? DB::table($table) : $where(DB::table($table)))->count());
    }

    /**
     * ViA strategy with an applied (test-format) report, so decisions reach the official state.
     */
    private function strategyReady(): void
    {
        config(['consent.reconciliation.max_age_hours' => 48, 'consent.reconciliation.format' => 'test', 'consent.reconciliation.parsers' => ['test' => TestChangeReportParser::class]]);
        $this->inTenant($this->tenant, fn () => app(ConsentStrategy::class)->set(ConsentStrategyMode::ViaReconciliation, 'test', Actor::console('test')));
        $this->report("other@example.com;EMAIL;INDIVIDUAL;ONAY;2026-10-20T10:00:00Z;HS_WEB\n");
    }

    private function report(string $rows): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'iys');
        file_put_contents($path, "address;channel;recipient_type;status;occurred_at;source\n".$rows);
        $this->inTenant($this->tenant, function () use ($path) {
            $importer = app(IysReconciliationImporter::class);
            $importer->apply($importer->analyze(ReconciliationFile::open($path, 'r.csv'), CarbonImmutable::today(), null, Actor::console('test'))['batch_id'], Actor::console('test'));
        });
    }

    private function reason(): EligibilityReason
    {
        return $this->inTenant($this->tenant, fn () => app(SendEligibility::class)->forCustomer($this->customer)->reason);
    }

    public function test_pending_form_cannot_acquire_production_consent_and_readiness_waits(): void
    {
        $this->igsForm();
        $this->fakeTransport();
        $reference = $this->request();

        $status = $this->viaStatus();
        $this->assertSame(['form_pending_approval', 'WAIT', 'VIA_FORM_PENDING_APPROVAL'], [$status->state, $status->level(), $status->blocker()]);
        $this->assertSame('ViA OTP form is awaiting İYS approval.', $status->detail);
        $this->assertSame('VIA_FORM_PENDING_APPROVAL', $this->refused(fn () => $this->otp('start', $reference)));
        $this->assertSame('VIA_FORM_PENDING_APPROVAL', $this->refused(fn () => $this->otp('verify', $reference, '123456')));
        $this->assertSame([0, 0], [$this->transport->starts, $this->transport->verifies]);
        $this->assertSame(0, $this->rows('consents', fn ($q) => $q->where('source_provider', 'VIA')));

        $row = collect($this->inTenant($this->tenant, fn () => app(ExternalBlockers::class)->all()))->firstWhere('blocker', 'IYS_VIA');
        $this->assertSame(['WAIT', 'ViA OTP form is awaiting İYS approval.'], [$row['status'], $row['detail']]);
    }

    public function test_approval_alone_is_not_enough_the_technical_configuration_must_exist(): void
    {
        $this->igsForm();
        $this->approve();
        $reference = $this->request();

        // Production binding: NOT_CONFIGURED transport.
        $this->assertSame(['transport_not_configured', 'WAIT'], [$this->viaStatus()->state, $this->viaStatus()->level()]);
        $this->assertSame('VIA_TRANSPORT_NOT_CONFIGURED', $this->refused(fn () => $this->otp('start', $reference)));

        $this->fakeTransport();
        $this->assertTrue($this->viaStatus()->configured());
        $this->assertSame('fake-otp-'.$reference, $this->otp('start', $reference));
    }

    public function test_missing_form_id_fails_closed_and_cannot_be_approved(): void
    {
        $this->igsForm(formId: null);
        $this->fakeTransport();

        $this->assertSame(['form_id_missing', 'BLOCKED'], [$this->viaStatus()->state, $this->viaStatus()->level()]);
        $this->expectException(InvalidArgumentException::class);
        $this->approve();
    }

    public function test_a_fake_transport_is_refused_in_production(): void
    {
        $this->igsForm();
        $this->approve();
        $this->fakeTransport();
        $reference = $this->request();

        $this->app->detectEnvironment(fn () => 'production');
        try {
            $this->assertSame('fake_transport_in_production', $this->viaStatus()->state);
            $this->assertSame('VIA_FAKE_TRANSPORT_IN_PRODUCTION', $this->refused(fn () => $this->otp('start', $reference)));
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
        $this->assertSame(0, $this->transport->starts);
    }

    public function test_a_verified_result_records_via_onay_with_provenance_once(): void
    {
        $this->igsForm();
        $this->approve();
        $this->fakeTransport();
        $reference = $this->request();
        $this->otp('start', $reference);

        $this->assertSame('applied', $this->otp('verify', $reference, '123456'));
        $this->assertSame('duplicate', $this->otp('verify', $reference, '123456'));

        $consent = $this->inTenant($this->tenant, fn () => DB::table('consents')->where('source_provider', 'VIA')->sole());
        $this->assertSame(['GRANTED', 'EMAIL', 'VIA_OTP'], [$consent->status, $consent->channel, $consent->source]);
        $evidence = json_decode((string) $consent->evidence, true);
        foreach (['source' => 'VIA', 'provider' => 'IYS_VIA', 'form_type' => 'OTP', 'form_id' => self::FORM_ID, 'approval_type' => 'ETK', 'channel' => 'EMAIL', 'status' => 'ONAY',
            'provider_reference' => 'fake-otp-'.$reference] as $k => $v) {
            $this->assertSame($v, $evidence[$k], $k);
        }
        $this->assertArrayHasKey('occurred_at', $evidence);
        $this->assertArrayHasKey('recorded_at', $evidence);
        $this->assertStringNotContainsString('123456', (string) json_encode($this->inTenant($this->tenant, fn () => DB::table('via_consent_requests')->get())));
        $this->assertSame(1, $this->rows('customer_events', fn ($q) => $q->where('name', 'NEWSLETTER_SUBSCRIBED')));
    }

    public function test_a_provider_failure_never_creates_onay(): void
    {
        $this->igsForm();
        $this->approve();
        $this->fakeTransport();
        $reference = $this->request();
        $this->assertSame('VIA_OTP_NOT_STARTED', $this->refused(fn () => $this->otp('verify', $reference, '123456')));
        $this->otp('start', $reference);

        $this->assertSame('failed', $this->otp('verify', $reference, '000000'));
        $this->transport->next = new ViaOtpVerifyResult(true, null);
        $this->assertSame('failed', $this->otp('verify', $reference, '123456'), 'Verified without a provider reference is not proof.');
        $this->transport->next = new ViaOtpVerifyResult(true, 'ref', channel: ConsentChannel::Sms);
        $this->assertSame('failed', $this->otp('verify', $reference, '123456'), 'ETK for another channel is not e-mail consent.');

        $this->assertSame(0, $this->rows('consents', fn ($q) => $q->where('source_provider', 'VIA')));
        $request = $this->inTenant($this->tenant, fn () => DB::table('via_consent_requests')->sole());
        $this->assertSame(['pending', 3, 'CHANNEL_MISMATCH'], [$request->state, (int) $request->otp_attempts, $request->failure_code]);
    }

    public function test_local_ret_and_a_later_reconciliation_ret_override_via_onay(): void
    {
        $this->igsForm();
        $this->approve();
        $this->fakeTransport();
        $this->strategyReady();
        $reference = $this->request();
        $this->otp('start', $reference);
        $this->otp('verify', $reference, '123456');
        $this->assertSame(EligibilityReason::Allowed, $this->reason());

        $this->report('ayse@example.com;EMAIL;INDIVIDUAL;RET;'.CarbonImmutable::now()->addSecond()->toIso8601String().";VATANDAS\n");
        $this->assertSame(EligibilityReason::IysRevoked, $this->reason());

        $token = app(UnsubscribeTokens::class)->issue($this->tenant->id, (string) $this->inTenant($this->tenant, fn () => DB::table('consent_recipients')->where('address', 'ayse@example.com')->value('id')));
        app(Unsubscribes::class)->apply($token, 'link');
        $this->assertSame(EligibilityReason::LocalSuppressed, $this->reason());
    }

    public function test_local_unsubscribe_wins_over_a_fresh_via_onay(): void
    {
        $this->igsForm();
        $this->approve();
        $this->fakeTransport();
        $this->strategyReady();
        $recipient = (string) $this->inTenant($this->tenant, fn () => DB::table('consent_recipients')->where('address', 'ayse@example.com')->value('id'));
        app(Unsubscribes::class)->apply(app(UnsubscribeTokens::class)->issue($this->tenant->id, $recipient), 'one_click');

        $reference = $this->request();
        $this->otp('start', $reference);
        $this->assertSame('applied', $this->otp('verify', $reference, '123456'));
        $this->assertSame(EligibilityReason::LocalSuppressed, $this->reason());
        $this->assertSame(1, $this->rows('consent_conflicts', fn ($q) => $q->where('kind', 'OFFICIAL_ONAY_AFTER_LOCAL_RET')));
    }

    public function test_via_configuration_is_tenant_isolated(): void
    {
        $this->igsForm();
        $this->approve();
        $this->fakeTransport();
        $reference = $this->request();
        [$other] = $this->createTenant('other');

        $this->assertSame('not_configured', $this->inTenant($other, fn () => app(ViaConsentProvider::class)->status()->state));
        $this->assertNull($this->inTenant($other, fn () => app(ViaConfigurations::class)->current()));
        $this->igsForm(tenant: $other);
        $this->assertSame('form_pending_approval', $this->inTenant($other, fn () => app(ViaConsentProvider::class)->status()->state), 'Approval of one tenant never applies to another.');
        $this->assertSame('VIA_FORM_PENDING_APPROVAL', $this->refused(fn () => $this->inTenant($other, fn () => app(ViaOtpConsentProvider::class)->start($reference))));
        $this->assertSame(0, $this->transport->starts);
    }

    public function test_configuration_cannot_approve_and_a_new_form_id_needs_approval_again(): void
    {
        try {
            $this->inTenant($this->tenant, fn () => app(ViaConfigurations::class)->configure(['form_type' => 'OTP', 'approval_type' => 'ETK', 'form_id' => self::FORM_ID, 'form_status' => 'approved'], Actor::console('test')));
            $this->fail('Configuration must not approve a form.');
        } catch (InvalidArgumentException) {
        }
        $this->igsForm();
        $this->approve();
        $this->inTenant($this->tenant, fn () => app(ViaConfigurations::class)->configure(['form_id' => 'TESTFORM0002'], Actor::console('test')));
        $this->assertSame('pending_approval', $this->inTenant($this->tenant, fn () => app(ViaConfigurations::class)->current()?->formStatus->value));

        $this->artisan('consent:via', ['--tenant' => 'shop', '--form-status' => 'approved'])->assertFailed();
        $this->artisan('consent:via', ['--tenant' => 'shop'])->assertSuccessful()->expectsOutputToContain('form_pending_approval');
    }

    public function test_form_status_change_in_the_panel_is_authorized_confirmed_and_audited(): void
    {
        $this->igsForm();
        $as = fn (User $u) => $this->actingAs($u, 'web')->withSession([PanelTenant::SESSION_TENANT => $this->tenant->id, PanelRecentAuth::SESSION_KEY => time()]);

        $as($this->owner)->get('/app/integrations')->assertOk()->assertSee('İYS ViA')->assertSee('Onay bekliyor')->assertSee('TE••••••••01')->assertDontSee(self::FORM_ID)
            ->assertSee('ViA OTP form is awaiting İYS approval.')->assertSee('Kapalı');
        $as($this->owner)->get('/app/readiness')->assertOk()->assertSee('Bekliyor');

        $marketer = $this->addMember($this->tenant, TenantRole::Marketer);
        $as($marketer)->post('/app/integrations/via/form-status', ['status' => 'approved', 'note' => 'x', 'portal_confirmed' => 1])->assertForbidden();
        $as($this->owner)->post('/app/integrations/via/form-status', ['status' => 'approved', 'note' => 'x'])->assertSessionHasErrors('action');
        $this->assertSame(0, $this->rows('audit_logs', fn ($q) => $q->where('action', 'consent.via_form_status_changed')));

        $as($this->owner)->post('/app/integrations/via/form-status', ['status' => 'approved', 'note' => 'İYS portalında Onaylandı görüldü', 'portal_confirmed' => 1])->assertRedirect();
        $audit = $this->inTenant($this->tenant, fn () => DB::table('audit_logs')->where('action', 'consent.via_form_status_changed')->sole());
        $meta = json_decode((string) $audit->metadata, true);
        $this->assertSame(['pending_approval', 'approved', true], [$meta['from'], $meta['to'], $meta['portal_confirmed']]);
        $this->assertStringNotContainsString(self::FORM_ID, (string) json_encode($this->inTenant($this->tenant, fn () => DB::table('audit_logs')->get())));

        // Approved, but the official transport is not configured: still no acquisition.
        $this->assertSame(['transport_not_configured', 'WAIT'], [$this->viaStatus()->state, $this->viaStatus()->level()]);
    }
}
