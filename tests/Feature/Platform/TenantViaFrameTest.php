<?php

namespace Tests\Feature\Platform;

use App\Consent\Via\ViaConfig;
use App\Consent\Via\ViaConfigurations;
use App\Consent\Via\ViaConsentProvider;
use App\Consent\Via\ViaFrameOrigins;
use App\Consent\Via\ViaFrameReadiness;
use App\Consent\Via\ViaOtpConsentProvider;
use App\Consent\Via\ViaOtpStartRequest;
use App\Consent\Via\ViaOtpStartResult;
use App\Consent\Via\ViaOtpTransport;
use App\Consent\Via\ViaOtpVerifyRequest;
use App\Consent\Via\ViaOtpVerifyResult;
use App\Email\EmailLaunchReadiness;
use App\Http\Middleware\EnsurePlatformTwoFactor;
use App\Http\Middleware\PlatformRecentAuth;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Platform\TenantViaFrame;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use LogicException;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Super Admin › Müşteriler › Entegrasyonlar › İYS / ViA: tenant-scoped ViA
 * Frame configuration, readiness and embed code. Configuration only: no
 * call to ViA or İYS, no OTP, no consent record.
 */
final class TenantViaFrameTest extends TestCase
{
    use InteractsWithTenants;

    private const FORM_ID = '123e4567-e89b-42d3-a456-426614174000';

    // Mixed case on purpose: stored and used exactly as İYS gave it.
    private const WIDGET = '9F1c2B3a-4d5E-4f60-8a7b-0C1d2E3f4A5b';

    private string $host;

    private PlatformAdmin $admin;

    private Tenant $igs;

    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->host = 'http://'.config('platform.admin_domain');
        config(['app.url' => 'http://localhost', 'site.owner_tenant' => null]);
        $admin = new PlatformAdmin(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'a-very-long-admin-password', 'status' => 'active']);
        $admin->setConnection('pgsql_owner')->save();
        $this->admin = PlatformAdmin::query()->findOrFail($admin->id);
        $this->admin->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(32), 'two_factor_confirmed_at' => now()])->save();
        $this->igs = $this->createTenant('igs')[0];
        $this->other = $this->createTenant('baska')[0];

        // Server-side OTP must never run from here.
        $this->app->instance(ViaOtpConsentProvider::class, new class implements ViaOtpConsentProvider
        {
            public function start(string $reference): string
            {
                throw new LogicException('OTP started');
            }

            public function verify(string $reference, string $code): string
            {
                throw new LogicException('OTP verified');
            }
        });
    }

    private function asAdmin(bool $recent = true): self
    {
        return $this->actingAs($this->admin, 'platform')->withSession([EnsurePlatformTwoFactor::SESSION_KEY => $this->admin->id, PlatformRecentAuth::SESSION_KEY => $recent ? time() : 0]);
    }

    private function page(?Tenant $tenant = null): TestResponse
    {
        return $this->asAdmin()->get("{$this->host}/tenants/".($tenant ?? $this->igs)->id.'/integrations/via');
    }

    /**
     * @param  array<string, string>  $input
     */
    private function save(array $input, ?Tenant $tenant = null, bool $recent = true): TestResponse
    {
        $t = $tenant ?? $this->igs;

        return $this->asAdmin($recent)->from("{$this->host}/tenants/{$t->id}/integrations/via")->post("{$this->host}/tenants/{$t->id}/integrations/via", $input);
    }

    private function formStatus(string $to, bool $confirmed = false): TestResponse
    {
        return $this->asAdmin()->from("{$this->host}/tenants/{$this->igs->id}/integrations/via")
            ->post("{$this->host}/tenants/{$this->igs->id}/integrations/via/status", ['status' => $to, 'note' => 'İYS portalında görüldü', 'portal_confirmed' => $confirmed ? '1' : '0']);
    }

    private function activate(string $origin = 'https://igs.net.tr', bool $confirmed = true): TestResponse
    {
        return $this->asAdmin()->from("{$this->host}/tenants/{$this->igs->id}/integrations/via")
            ->post("{$this->host}/tenants/{$this->igs->id}/integrations/via/domain-activation", ['origin' => $origin, 'note' => 'İYS aktivasyon e-postası', 'iys_confirmed' => $confirmed ? '1' : '0']);
    }

    private function config(?Tenant $tenant = null): ?ViaConfig
    {
        return $this->inTenant($tenant ?? $this->igs, fn () => app(ViaConfigurations::class)->current());
    }

    private function configureIgs(): void
    {
        $this->save(['iys_number' => '620874', 'brand' => 'İGS', 'package_status' => 'active', 'form_id' => strtoupper(self::FORM_ID), 'widget_uuid' => ' '.self::WIDGET.' ',
            'frame_origins' => "https://IGS.net.tr/\nhttps://www.igs.net.tr, https://igs.net.tr:443"])->assertSessionHasNoErrors();
    }

    public function test_the_super_admin_configures_the_tenants_own_via_frame(): void
    {
        $this->page()->assertOk()->assertSee('Yapılandırılmadı')->assertDontSee('load-widgetloader');
        $this->asAdmin()->get("{$this->host}/tenants/{$this->igs->id}?tab=entegrasyonlar")->assertOk()
            ->assertSee(route('admin.tenants.via', $this->igs->id), false)->assertSee('İYS / ViA yapılandırması');

        $this->configureIgs();
        $c = $this->config();
        $this->assertSame(['620874', 'İGS', 'active', 'OTP', 'ETK', strtoupper(self::FORM_ID), self::WIDGET, 'draft'],
            [$c->iysNumber, $c->brand, $c->packageStatus, $c->formType, $c->approvalType, $c->formId, $c->widgetUuid, $c->formStatus->value], 'Form ID and Widget UUID keep their case.');
        $this->assertSame(['https://igs.net.tr', 'https://www.igs.net.tr'], $c->frameOrigins, 'Normalized, duplicates removed.');
        $this->page()->assertOk()->assertSee('Taslak / eksik')->assertDontSee('load-widgetloader');

        // Approval needs the portal confirmation and follows the status flow.
        $this->formStatus('approved', true)->assertSessionHasErrors('via_status');
        $this->formStatus('pending_approval')->assertSessionHasNoErrors();
        $this->formStatus('approved')->assertSessionHasErrors('via_status');
        $this->formStatus('approved', true)->assertSessionHasNoErrors();

        // An approved form alone is not ready: İYS's domain activation is confirmed by a Super Admin.
        $this->page()->assertOk()->assertSee('Taslak / eksik')->assertDontSee('load-widgetloader');
        $this->activate(confirmed: false)->assertSessionHasErrors('via_activation');
        $this->activate('https://baska.com.tr')->assertSessionHasErrors('via_activation');
        $this->assertNull($this->config()->domainActivation);
        $this->activate()->assertSessionHasNoErrors();
        $this->assertSame('https://igs.net.tr', $this->config()->activatedOrigin());

        // www.igs.net.tr is not the activated host: the Frame would only say "Geçersiz alan adı".
        $this->assertSame('problem', app(TenantViaFrame::class)->show($this->igs->id)['readiness']->state);
        $this->page()->assertOk()->assertSee('Sorunlu')->assertDontSee('load-widgetloader');
        $this->save(['frame_origins' => 'https://igs.net.tr'])->assertSessionHasNoErrors();
        $this->assertNull($this->config()->activatedOrigin(), 'Changing the origins drops the confirmation.');
        $this->assertNull($this->config()->domainActivation);
        $this->activate()->assertSessionHasNoErrors();

        $snippet = '<script src="https://viaframe.iys.org.tr/widget/load-widgetloader/'.self::WIDGET.'" defer></script>'."\n".'<div id="'.self::WIDGET.'"></div>';
        $this->page()->assertOk()->assertSee('Hazır (İYS domain aktivasyonu teyitli)')->assertSee($snippet)->assertDontSee('Doğrulandı<');
        $shown = app(TenantViaFrame::class)->show($this->igs->id);
        $this->assertTrue($shown['readiness']->ready());
        $this->assertSame($snippet, $shown['snippet']);
        $this->assertStringNotContainsString(self::FORM_ID, strtolower($snippet), 'The snippet uses the Widget UUID, not the portal Form ID.');
        $public = DB::connection('pgsql_platform')->table('tenants')->where('id', $this->igs->id)->value('public_id');
        foreach ([$this->igs->id, $public, 'igs"', $c->integrationId, 'token', 'secret', 'key'] as $internal) {
            $this->assertStringNotContainsString($internal, $snippet);
        }

        // Tenant-scoped: the other tenant has nothing, and cannot see this row even through RLS.
        $this->assertNull($this->config($this->other));
        $this->page($this->other)->assertOk()->assertSee('Yapılandırılmadı')->assertDontSee(self::FORM_ID);
        $this->assertSame(0, $this->inTenant($this->other, fn () => DB::table('tenant_integrations')->where('provider', 'via')->count()));
        $this->asAdmin()->get("{$this->host}/tenants/{$this->other->id}?tab=entegrasyonlar")->assertOk()->assertSee('Yapılandırılmadı');
        $this->asAdmin()->get("{$this->host}/tenants/{$this->igs->id}?tab=entegrasyonlar")->assertOk()->assertSee('Yapılandırıldı (doğrulanmadı)');
        $this->asAdmin()->get("{$this->host}/tenants/00000000-0000-7000-8000-000000000000/integrations/via")->assertNotFound();

        // Audit: who and what changed, never the Form ID value.
        $audit = DB::connection('pgsql_platform')->table('audit_logs')->whereIn('action', ['consent.via_configured', 'consent.via_form_status_changed', 'consent.via_domain_activation_confirmed'])
            ->orderBy('created_at')->orderBy('id')->get(['action', 'tenant_id', 'actor_type', 'metadata']);
        $this->assertSame(['consent.via_configured', 'consent.via_form_status_changed', 'consent.via_form_status_changed', 'consent.via_domain_activation_confirmed',
            'consent.via_configured', 'consent.via_domain_activation_confirmed'], $audit->pluck('action')->all());
        $this->assertEquals(['origin' => 'https://igs.net.tr', 'note' => 'İYS aktivasyon e-postası', 'iys_confirmed' => true], json_decode((string) $audit->last()->metadata, true));
        $this->assertTrue(json_decode((string) $audit[4]->metadata, true)['domain_activation_cleared']);
        $this->assertSame([$this->igs->id], $audit->pluck('tenant_id')->unique()->values()->all());
        $this->assertSame(['platform_admin'], $audit->pluck('actor_type')->unique()->values()->all());
        $first = json_decode((string) $audit->first()->metadata, true);
        $this->assertTrue($first['created']);
        $this->assertEqualsCanonicalizing(['iys_number', 'brand', 'package_status', 'form_id', 'widget_uuid', 'form_type', 'approval_type', 'frame_origins', 'form_status'], $first['changed']);
        $this->assertStringNotContainsString(self::FORM_ID, strtolower($audit->toJson()));

        // An update records only what changed; an unchanged save keeps the confirmation.
        $this->save(['brand' => 'İGS Mağazacılık', 'frame_origins' => 'https://igs.net.tr', 'widget_uuid' => self::WIDGET])->assertSessionHasNoErrors();
        $last = DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'consent.via_configured')->orderByDesc('created_at')->orderByDesc('id')->first(['metadata']);
        $this->assertEquals(['changed' => ['brand'], 'form_status' => 'approved', 'created' => false], json_decode((string) $last->metadata, true));
        $this->assertSame('https://igs.net.tr', $this->config()->activatedOrigin());
    }

    public function test_form_id_and_origins_are_validated(): void
    {
        foreach (['TESTFORM0002', 'abcd1234-5678', self::FORM_ID.'x', '<script>'] as $bad) {
            $this->save(['form_id' => $bad])->assertSessionHasErrors('via');
        }
        foreach (['http://igs.net.tr', 'https://igs.net.tr/izin', 'https://igs.net.tr?x=1', 'https://igs.net.tr#a', 'https://*.igs.net.tr', 'igs.net.tr',
            'https://kullanici@igs.net.tr', 'https://10.0.0.1', 'https://localhost', 'javascript:alert(1)', 'https://igs.net.tr:99999', 'https://igs..tr'] as $bad) {
            $this->save(['frame_origins' => "https://igs.net.tr\n".$bad])->assertSessionHasErrors('via');
        }
        $this->assertNull($this->config(), 'Nothing stored.');

        $this->assertSame(['https://a.com', 'https://a.com:8443', 'https://b.a.com'], ViaFrameOrigins::normalize(['https://A.com:443/', ' https://a.com ', 'https://a.com:8443', 'https://b.a.com', '']));
        $this->expectException(InvalidArgumentException::class);
        ViaFrameOrigins::normalize(array_map(fn ($i) => "https://s{$i}.igs.net.tr", range(1, 11)));
    }

    public function test_readiness_never_shows_an_incomplete_configuration_as_ready(): void
    {
        $activation = ['origin' => 'https://igs.net.tr', 'widget_uuid' => self::WIDGET, 'frame_origins' => ['https://igs.net.tr'], 'confirmed_at' => '2026-11-10T10:00:00+03:00',
            'confirmed_by' => null, 'note' => 'x'];
        $complete = ['iys_number' => '620874', 'brand' => 'İGS', 'package_status' => 'active', 'form_type' => 'OTP', 'approval_type' => 'ETK',
            'form_id' => self::FORM_ID, 'form_status' => 'approved', 'widget_uuid' => self::WIDGET, 'frame_origins' => ['https://igs.net.tr'], 'domain_activation' => $activation];
        $state = fn (array $s) => ViaFrameReadiness::of(ViaConfig::fromSettings('i', $s))->state;

        $this->assertSame('not_configured', ViaFrameReadiness::of(null)->state);
        $this->assertSame('ready', $state($complete));
        foreach (['iys_number', 'brand', 'package_status', 'form_id', 'widget_uuid', 'frame_origins', 'domain_activation'] as $missing) {
            $this->assertSame('incomplete', $state(array_diff_key($complete, [$missing => 1])), $missing);
        }
        $this->assertSame('incomplete', $state(['form_status' => 'pending_approval'] + $complete));
        $this->assertSame('incomplete', $state(['form_status' => 'draft'] + $complete));
        $this->assertSame('problem', $state(['form_status' => 'rejected'] + $complete));
        $this->assertSame('problem', $state(['form_status' => 'disabled'] + $complete));
        $this->assertSame('problem', $state(['package_status' => 'inactive'] + $complete));
        $this->assertSame('problem', $state(['form_id' => 'TESTFORM0002'] + $complete));
        $this->assertSame('problem', $state(['widget_uuid' => 'via-frame=08b29340'] + $complete));

        // The confirmation is bound to the exact Widget UUID (case-sensitive) and origin list.
        $this->assertSame('incomplete', $state(['widget_uuid' => strtolower(self::WIDGET)] + $complete));
        $this->assertSame('incomplete', $state(['domain_activation' => ['widget_uuid' => strtolower(self::WIDGET)] + $activation] + $complete));
        $this->assertSame('incomplete', $state(['frame_origins' => ['https://www.igs.net.tr']] + $complete));
        $this->assertSame('incomplete', $state(['domain_activation' => ['origin' => 'https://www.igs.net.tr'] + $activation] + $complete));
        $this->assertSame('incomplete', $state(['domain_activation' => ['origin' => 'https://igs.net.tr']] + $complete), 'A malformed confirmation never counts.');
        // Confirmed for igs.net.tr, but www is also listed: a problem, not ready.
        $both = ['https://igs.net.tr', 'https://www.igs.net.tr'];
        $this->assertSame('problem', $state(['frame_origins' => $both, 'domain_activation' => ['frame_origins' => $both] + $activation] + $complete));

        $this->assertNull(ViaFrameReadiness::of(ViaConfig::fromSettings('i', ['form_status' => 'pending_approval'] + $complete))->snippet(ViaConfig::fromSettings('i', $complete)));
        $ready = ViaConfig::fromSettings('i', $complete);
        $this->assertSame('<script src="https://viaframe.iys.org.tr/widget/load-widgetloader/'.self::WIDGET.'" defer></script>'."\n".'<div id="'.self::WIDGET.'"></div>',
            ViaFrameReadiness::of($ready)->snippet($ready));
    }

    public function test_the_widget_uuid_is_separate_kept_verbatim_and_changing_it_drops_the_activation(): void
    {
        foreach (['via-frame=08b29340-edc1-43be-a0a0-bf34999d92d5', 'TESTWIDGET', self::WIDGET.'x', '<script>'] as $bad) {
            $this->save(['widget_uuid' => $bad])->assertSessionHasErrors('via');
        }
        $this->assertNull($this->config());

        $this->save(['form_id' => self::FORM_ID, 'widget_uuid' => self::WIDGET, 'frame_origins' => 'https://igs.net.tr'])->assertSessionHasNoErrors();
        $this->activate()->assertSessionHasNoErrors();
        $c = $this->config();
        $this->assertSame([self::FORM_ID, self::WIDGET, 'https://igs.net.tr'], [$c->formId, $c->widgetUuid, $c->activatedOrigin()]);
        $raw = $this->inTenant($this->igs, fn () => json_decode((string) DB::table('tenant_integrations')->where('provider', 'via')->value('settings'), true));
        $this->assertSame(self::WIDGET, $raw['widget_uuid']);
        $this->assertSame(self::FORM_ID, $raw['form_id']);
        $this->assertArrayNotHasKey('dns_token', $raw);

        // Even a case-only change is a different Widget UUID: the confirmation drops.
        $this->save(['widget_uuid' => strtolower(self::WIDGET)])->assertSessionHasNoErrors();
        $c = $this->config();
        $this->assertSame(strtolower(self::WIDGET), $c->widgetUuid);
        $this->assertNull($c->domainActivation);
        $this->assertNull(app(TenantViaFrame::class)->show($this->igs->id)['snippet']);

        // The CLI stores it verbatim too.
        $this->assertSame(0, Artisan::call('consent:via', ['--tenant' => 'igs', '--widget-uuid' => self::WIDGET]), Artisan::output());
        $this->assertSame(self::WIDGET, $this->config()->widgetUuid);
    }

    public function test_frame_acquisition_readiness_is_a_warning_at_most_and_never_changes_sending(): void
    {
        $launch = fn () => $this->inTenant($this->igs, fn () => app(EmailLaunchReadiness::class)->evaluate(null));
        $before = $launch();
        $this->assertSame(['WARN', 'acquisition'], [$before['checks']['VIA_FRAME']['status'], $before['checks']['VIA_FRAME']['group']]);

        $this->configureIgs();
        $this->formStatus('pending_approval');
        $this->formStatus('approved', true);
        $this->save(['frame_origins' => 'https://igs.net.tr'])->assertSessionHasNoErrors();
        $this->assertStringContainsString('İYS domain aktivasyonu', $launch()['checks']['VIA_FRAME']['detail']);
        $this->assertSame('WARN', $launch()['checks']['VIA_FRAME']['status']);
        $this->activate()->assertSessionHasNoErrors();

        $after = $launch();
        $this->assertSame('PASS', $after['checks']['VIA_FRAME']['status']);
        $this->assertStringContainsString('https://igs.net.tr', (string) $after['checks']['VIA_FRAME']['detail']);
        $delivery = fn (array $l) => array_filter($l['checks'], fn ($c) => $c['group'] === 'delivery');
        $this->assertEquals($delivery($before), $delivery($after), 'Delivery checks do not depend on the Frame.');
        $this->assertSame([$before['sendable'], $before['counts'], $before['breakdown'], $before['strategy']], [$after['sendable'], $after['counts'], $after['breakdown'], $after['strategy']]);
    }

    public function test_configuration_never_contacts_iys_starts_an_otp_or_writes_consent(): void
    {
        $this->app->instance(ViaOtpTransport::class, new class implements ViaOtpTransport
        {
            public function configured(): bool
            {
                return false;
            }

            public function isFake(): bool
            {
                return false;
            }

            public function start(ViaConfig $config, ViaOtpStartRequest $request): ViaOtpStartResult
            {
                throw new LogicException('OTP transport used');
            }

            public function verify(ViaConfig $config, ViaOtpVerifyRequest $request): ViaOtpVerifyResult
            {
                throw new LogicException('OTP transport used');
            }
        });
        $this->configureIgs();
        $this->formStatus('pending_approval');
        $this->formStatus('approved', true);
        $this->save(['frame_origins' => 'https://igs.net.tr']);
        $this->activate()->assertSessionHasNoErrors();
        $this->page()->assertOk()->assertSee('load-widgetloader');

        Http::assertNothingSent();
        foreach (['consents', 'consent_events', 'consent_recipients', 'via_consent_requests', 'consent_write_intents'] as $table) {
            $this->assertSame(0, $this->inTenant($this->igs, fn () => DB::table($table)->count()), $table);
        }
        // Server-side ViA OTP (and with it direct İYS writes) stays closed.
        $status = $this->inTenant($this->igs, fn () => app(ViaConsentProvider::class)->status());
        $this->assertFalse($status->configured());
        $this->assertSame('transport_not_configured', $status->state);
        $this->assertFalse((bool) config('consent.iys.allow_production'));
    }

    public function test_changes_need_a_recent_password_and_the_platform_role_gains_nothing(): void
    {
        $this->save(['brand' => 'İGS'], recent: false)->assertRedirect(route('admin.confirm'));
        $this->asAdmin(false)->post("{$this->host}/tenants/{$this->igs->id}/integrations/via/domain-activation", ['origin' => 'https://igs.net.tr', 'note' => 'x', 'iys_confirmed' => '1'])
            ->assertRedirect(route('admin.confirm'));
        $this->asAdmin(false)->post("{$this->host}/tenants/{$this->igs->id}/integrations/via/status", ['status' => 'pending_approval', 'note' => 'x'])->assertRedirect(route('admin.confirm'));
        $this->assertNull($this->config());
        $this->flushSession();
        $this->actingAs($this->admin, 'platform')->get("{$this->host}/tenants/{$this->igs->id}/integrations/via")->assertRedirect(route('admin.two-factor.challenge'));

        $this->configureIgs();
        $platform = DB::connection('pgsql_platform');
        foreach (['SELECT settings FROM tenant_integrations', 'SELECT id FROM consents', 'SELECT address FROM consent_recipients', 'SELECT email FROM customers'] as $sql) {
            try {
                $platform->select($sql);
                $this->fail("Platform role ran: {$sql}");
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0], $sql);
            }
        }
        // Without a tenant context the runtime role sees no integration row (fail-closed).
        $this->assertSame(0, DB::table('tenant_integrations')->count());
        $this->assertSame(0, Artisan::call('db:verify-roles'), Artisan::output());
    }
}
