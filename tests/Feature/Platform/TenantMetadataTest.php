<?php

namespace Tests\Feature\Platform;

use App\Audit\Actor;
use App\Http\Middleware\EnsurePlatformTwoFactor;
use App\Http\Middleware\PanelRecentAuth;
use App\Http\Middleware\PanelTenant;
use App\Http\Middleware\PlatformRecentAuth;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Platform\TenantLifecycle;
use App\Tenancy\TenantDirectory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Super Admin › Müşteriler › Genel: editing a tenant's basic data keeps its
 * identity (id, public id, data key, members), needs a recent password and
 * the second factor, and is audited without the contact phone.
 */
final class TenantMetadataTest extends TestCase
{
    use InteractsWithTenants;

    private string $host;

    private PlatformAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = 'http://'.config('platform.admin_domain');
        config(['app.url' => 'http://localhost', 'site.owner_tenant' => null]);
        $admin = new PlatformAdmin(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'a-very-long-admin-password', 'status' => 'active']);
        $admin->setConnection('pgsql_owner')->save();
        $this->admin = PlatformAdmin::query()->findOrFail($admin->id);
        $this->admin->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(32), 'two_factor_confirmed_at' => now()])->save();
    }

    private function asAdmin(bool $recent = true, bool $twoFactor = true): self
    {
        return $this->actingAs($this->admin, 'platform')->withSession(array_filter([EnsurePlatformTwoFactor::SESSION_KEY => $twoFactor ? $this->admin->id : null,
            PlatformRecentAuth::SESSION_KEY => $recent ? time() : 0], fn ($v) => $v !== null));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function edit(Tenant $tenant, array $input, bool $recent = true, bool $twoFactor = true): TestResponse
    {
        return $this->asAdmin($recent, $twoFactor)->from("{$this->host}/tenants/{$tenant->id}")->post("{$this->host}/tenants/{$tenant->id}/metadata", $input + [
            'name' => $tenant->name, 'slug' => $tenant->slug, 'contact_phone' => $tenant->contact_phone, 'country' => $tenant->country, 'timezone' => $tenant->timezone,
            'currency' => $tenant->currency, 'locale' => $tenant->locale,
        ]);
    }

    private function fresh(Tenant $tenant): object
    {
        return DB::connection('pgsql_platform')->table('tenants')->where('id', $tenant->id)->sole(['id', 'public_id', 'name', 'slug', 'country', 'timezone', 'currency', 'locale', 'contact_phone', 'status']);
    }

    /**
     * @return Collection<int, object>
     */
    private function audits(string $action): Collection
    {
        return DB::connection('pgsql_platform')->table('audit_logs')->where('action', $action)->get(['tenant_id', 'actor_type', 'actor_id', 'metadata']);
    }

    public function test_the_super_admin_renames_a_tenant_and_keeps_its_identity(): void
    {
        [$igs, $owner] = $this->createTenant('igs-magazaclk');
        $other = $this->createTenant('baska-firma')[0];
        $before = $this->fresh($igs);
        $key = $this->inTenant($igs, fn () => DB::table('tenant_data_keys')->get(['id', 'version', 'wrapped_key'])->toJson());
        $members = $this->inTenant($igs, fn () => DB::table('tenant_users')->orderBy('id')->get(['id', 'user_id', 'role', 'status'])->toJson());
        $this->createIntegration($igs);

        $this->edit($igs, ['name' => 'İGS Mağazacılık A.Ş.', 'slug' => 'igs', 'slug_confirmation' => 'igs', 'contact_phone' => '+90 212 000 00 00',
            'country' => 'tr', 'currency' => 'try'])->assertSessionHasNoErrors()->assertSessionHas('status', 'Müşteri bilgileri güncellendi.');

        $after = $this->fresh($igs);
        $this->assertSame([$before->id, $before->public_id, 'active'], [$after->id, $after->public_id, $after->status], 'Identity and status unchanged.');
        $this->assertSame(['İGS Mağazacılık A.Ş.', 'igs', 'TR', 'TRY', 'Europe/Istanbul', 'tr', '+90 212 000 00 00'],
            [$after->name, $after->slug, $after->country, $after->currency, $after->timezone, $after->locale, $after->contact_phone]);
        $this->assertSame($key, $this->inTenant($igs, fn () => DB::table('tenant_data_keys')->get(['id', 'version', 'wrapped_key'])->toJson()), 'Data key untouched.');
        $this->assertSame($members, $this->inTenant($igs, fn () => DB::table('tenant_users')->orderBy('id')->get(['id', 'user_id', 'role', 'status'])->toJson()));
        $this->assertSame(1, $this->inTenant($igs, fn () => DB::table('tenant_integrations')->count()));
        $this->assertSame('baska-firma', $this->fresh($other)->slug, 'The other tenant is untouched.');

        // Operator commands find it by the new code only; the old one is free.
        $this->assertSame($igs->id, app(TenantDirectory::class)->idBySlug('igs'));
        $this->assertNull(app(TenantDirectory::class)->idBySlug('igs-magazaclk'));
        // Its members keep working: the panel uses the tenant id.
        $this->actingAs($owner, 'web')->withSession([PanelTenant::SESSION_TENANT => $igs->id, PanelRecentAuth::SESSION_KEY => time()])
            ->get('http://localhost/app/')->assertOk()->assertSee('İGS Mağazacılık A.Ş.');

        $audit = $this->audits('tenant.metadata_updated')->sole();
        $this->assertSame([$igs->id, 'platform_admin', $this->admin->id], [$audit->tenant_id, $audit->actor_type, $audit->actor_id]);
        $meta = json_decode((string) $audit->metadata, true);
        // "tr" / "try" normalize to the stored values: not a change.
        $this->assertEqualsCanonicalizing(['name', 'slug', 'contact_phone'], $meta['changed']);
        $this->assertSame(['name' => 'Igs-Magazaclk', 'slug' => 'igs-magazaclk'], $meta['from']);
        $this->assertSame(['name' => 'İGS Mağazacılık A.Ş.', 'slug' => 'igs'], $meta['to']);
        $this->assertStringNotContainsString('212 000', (string) $audit->metadata, 'The phone number is not audited.');

        $this->asAdmin()->get("{$this->host}/tenants/{$igs->id}")->assertOk()->assertSee('İGS Mağazacılık A.Ş.')->assertSee('igs');
    }

    public function test_a_code_change_needs_confirmation_and_a_valid_unused_code(): void
    {
        [$igs] = $this->createTenant('igs-magazaclk');
        $this->createTenant('igs');
        $this->createTenant('askida');
        app(TenantLifecycle::class)->suspend(app(TenantDirectory::class)->idBySlug('askida'), 'test', Actor::system());

        $this->edit($igs, ['slug' => 'yeni-kod'])->assertSessionHasErrors('metadata');
        $this->edit($igs, ['slug' => 'yeni-kod', 'slug_confirmation' => 'yeni-kot'])->assertSessionHasErrors('metadata');
        $this->edit($igs, ['slug' => 'igs', 'slug_confirmation' => 'igs'])->assertSessionHasErrors(['metadata' => 'Bu kod başka bir müşteride kullanılıyor; farklı bir kod seçin.']);
        $this->edit($igs, ['slug' => 'askida', 'slug_confirmation' => 'askida'])->assertSessionHasErrors('metadata', 'A suspended tenant keeps its code.');
        foreach (['a', 'ig_s', '-igs', 'igs-', 'ig--s', 'admin', 'app', 'İgs', str_repeat('a', 64)] as $bad) {
            $this->edit($igs, ['slug' => $bad, 'slug_confirmation' => $bad])->assertSessionHasErrors();
        }
        foreach ([['country' => 'TUR'], ['currency' => 'TL'], ['timezone' => 'Europe/Ankara'], ['locale' => 'turkish'], ['name' => 'x'], ['contact_phone' => 'ara beni']] as $bad) {
            $this->edit($igs, $bad)->assertSessionHasErrors();
        }
        $this->assertSame('igs-magazaclk', $this->fresh($igs)->slug);
        $this->assertCount(0, $this->audits('tenant.metadata_updated'));

        $this->edit($igs, [])->assertSessionHas('status', 'Değişiklik yok.');
        $this->assertCount(0, $this->audits('tenant.metadata_updated'));
    }

    public function test_the_website_owner_keeps_its_code(): void
    {
        [$site] = $this->createTenant('pratiktools');
        config(['site.owner_tenant' => 'pratiktools']);

        $this->asAdmin()->get("{$this->host}/tenants/{$site->id}")->assertOk()->assertSee('değiştirilemez: web sitesi sahibini koduyla bulur', false);
        $this->edit($site, ['slug' => 'pratik', 'slug_confirmation' => 'pratik'])->assertSessionHasErrors('metadata');
        $this->assertSame('pratiktools', $this->fresh($site)->slug);
        $this->edit($site, ['name' => 'PratikTools Yazılım'])->assertSessionHasNoErrors();
        $this->assertSame('PratikTools Yazılım', $this->fresh($site)->name);
    }

    public function test_edits_need_a_recent_password_and_the_second_factor(): void
    {
        [$igs, $owner] = $this->createTenant('igs-magazaclk');
        $change = ['name' => 'İGS Mağazacılık A.Ş.'];

        $this->edit($igs, $change, recent: false)->assertRedirect(route('admin.confirm'));
        $this->flushSession();
        $this->edit($igs, $change, twoFactor: false)->assertRedirect(route('admin.two-factor.challenge'));
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post("{$this->host}/tenants/{$igs->id}/metadata", $change)->assertRedirect(route('admin.login'));
        // A tenant user (even its owner) has no platform session.
        $this->actingAs($owner, 'web')->post("{$this->host}/tenants/{$igs->id}/metadata", $change)->assertRedirect(route('admin.login'));
        $this->assertSame('Igs-Magazaclk', $this->fresh($igs)->name);

        $this->app['auth']->forgetGuards();
        $this->edit($igs, $change, recent: true)->assertSessionHasNoErrors();
        $this->asAdmin()->post("{$this->host}/tenants/00000000-0000-7000-8000-000000000000/metadata", $change)->assertNotFound();
    }
}
