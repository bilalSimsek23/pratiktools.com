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

    private function config(?Tenant $tenant = null): ?ViaConfig
    {
        return $this->inTenant($tenant ?? $this->igs, fn () => app(ViaConfigurations::class)->current());
    }

    private function configureIgs(): void
    {
        $this->save(['iys_number' => '620874', 'brand' => 'İGS', 'package_status' => 'active', 'form_id' => strtoupper(self::FORM_ID),
            'frame_origins' => "https://IGS.net.tr/\nhttps://www.igs.net.tr, https://igs.net.tr:443"])->assertSessionHasNoErrors();
    }

    public function test_the_super_admin_configures_the_tenants_own_via_frame(): void
    {
        $this->page()->assertOk()->assertSee('Yapılandırılmadı')->assertDontSee('load-widgetloader');
        $this->asAdmin()->get("{$this->host}/tenants/{$this->igs->id}?tab=entegrasyonlar")->assertOk()
            ->assertSee(route('admin.tenants.via', $this->igs->id), false)->assertSee('İYS / ViA yapılandırması');

        $this->configureIgs();
        $c = $this->config();
        $this->assertSame(['620874', 'İGS', 'active', 'OTP', 'ETK', self::FORM_ID, 'draft'], [$c->iysNumber, $c->brand, $c->packageStatus, $c->formType, $c->approvalType, $c->formId, $c->formStatus->value]);
        $this->assertSame(['https://igs.net.tr', 'https://www.igs.net.tr'], $c->frameOrigins, 'Normalized, duplicates removed.');
        $this->page()->assertOk()->assertSee('Taslak / eksik')->assertDontSee('load-widgetloader');

        // Approval needs the portal confirmation and follows the status flow.
        $this->formStatus('approved', true)->assertSessionHasErrors('via_status');
        $this->formStatus('pending_approval')->assertSessionHasNoErrors();
        $this->formStatus('approved')->assertSessionHasErrors('via_status');
        $this->formStatus('approved', true)->assertSessionHasNoErrors();

        $snippet = '<script src="https://viaframe.iys.org.tr/widget/load-widgetloader/'.self::FORM_ID.'" defer></script>'."\n".'<div id="'.self::FORM_ID.'"></div>';
        $this->page()->assertOk()->assertSee('Yapılandırma hazır (ViA tarafından doğrulanmadı)')->assertSee($snippet)->assertDontSee('Doğrulandı<');
        $shown = app(TenantViaFrame::class)->show($this->igs->id);
        $this->assertTrue($shown['readiness']->ready());
        $this->assertSame($snippet, $shown['snippet']);
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
        $audit = DB::connection('pgsql_platform')->table('audit_logs')->whereIn('action', ['consent.via_configured', 'consent.via_form_status_changed'])
            ->orderBy('created_at')->get(['action', 'tenant_id', 'actor_type', 'metadata']);
        $this->assertSame(['consent.via_configured', 'consent.via_form_status_changed', 'consent.via_form_status_changed'], $audit->pluck('action')->all());
        $this->assertSame([$this->igs->id], $audit->pluck('tenant_id')->unique()->values()->all());
        $this->assertSame(['platform_admin'], $audit->pluck('actor_type')->unique()->values()->all());
        $first = json_decode((string) $audit->first()->metadata, true);
        $this->assertTrue($first['created']);
        $this->assertEqualsCanonicalizing(['iys_number', 'brand', 'package_status', 'form_id', 'form_type', 'approval_type', 'frame_origins', 'form_status'], $first['changed']);
        $this->assertStringNotContainsString(self::FORM_ID, $audit->toJson());

        // An update records only what changed.
        $this->save(['frame_origins' => 'https://igs.net.tr'])->assertSessionHasNoErrors();
        $last = DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'consent.via_configured')->orderByDesc('created_at')->first(['metadata']);
        $this->assertEquals(['changed' => ['frame_origins'], 'form_status' => 'approved', 'created' => false], json_decode((string) $last->metadata, true));
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
        $complete = ['iys_number' => '620874', 'brand' => 'İGS', 'package_status' => 'active', 'form_type' => 'OTP', 'approval_type' => 'ETK',
            'form_id' => self::FORM_ID, 'form_status' => 'approved', 'frame_origins' => ['https://igs.net.tr']];
        $state = fn (array $s) => ViaFrameReadiness::of(ViaConfig::fromSettings('i', $s))->state;

        $this->assertSame('not_configured', ViaFrameReadiness::of(null)->state);
        $this->assertSame('ready', $state($complete));
        foreach (['iys_number', 'brand', 'package_status', 'form_id', 'frame_origins'] as $missing) {
            $this->assertSame('incomplete', $state(array_diff_key($complete, [$missing => 1])), $missing);
        }
        $this->assertSame('incomplete', $state(['form_status' => 'pending_approval'] + $complete));
        $this->assertSame('incomplete', $state(['form_status' => 'draft'] + $complete));
        $this->assertSame('problem', $state(['form_status' => 'rejected'] + $complete));
        $this->assertSame('problem', $state(['form_status' => 'disabled'] + $complete));
        $this->assertSame('problem', $state(['package_status' => 'inactive'] + $complete));
        $this->assertSame('problem', $state(['form_id' => 'TESTFORM0002'] + $complete));
        $this->assertNull(ViaFrameReadiness::of(ViaConfig::fromSettings('i', ['form_status' => 'pending_approval'] + $complete))->snippet(ViaConfig::fromSettings('i', $complete)));
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
