<?php

namespace Tests\Feature\Platform;

use App\Audit\Actor;
use App\Http\Middleware\EnsurePlatformTwoFactor;
use App\Http\Middleware\PanelRecentAuth;
use App\Http\Middleware\PanelTenant;
use App\Http\Middleware\PlatformRecentAuth;
use App\Models\PlatformAdmin;
use App\Models\TenantUser;
use App\Models\User;
use App\Site\SiteOwner;
use App\Tenancy\ProvisioningError;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDirectory;
use App\Tenancy\TenantProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Platform Yönetimi › Müşteriler: create a tenant from the panel, invite
 * and activate its owner, manage members, suspend and reactivate — through
 * the same provisioning service as `tenant:create`, never weakening RLS.
 */
final class TenantManagementTest extends TestCase
{
    use InteractsWithTenants;

    private string $host;

    private PlatformAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = 'http://'.config('platform.admin_domain');
        config(['app.url' => 'http://localhost']);
        $admin = new PlatformAdmin(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'a-very-long-admin-password', 'status' => 'active']);
        $admin->setConnection('pgsql_owner')->save();
        $this->admin = PlatformAdmin::query()->findOrFail($admin->id);
        $this->admin->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(32), 'two_factor_confirmed_at' => now()])->save();
    }

    private function asAdmin(bool $recent = true): self
    {
        return $this->actingAs($this->admin, 'platform')->withSession([EnsurePlatformTwoFactor::SESSION_KEY => $this->admin->id, PlatformRecentAuth::SESSION_KEY => $recent ? time() : 0]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function createViaPanel(array $input = []): TestResponse
    {
        return $this->asAdmin()->post("{$this->host}/tenants", $input + ['name' => 'ABC Tekstil', 'slug' => 'abc-tekstil', 'owner_name' => 'Ayşe Yılmaz', 'owner_email' => 'ayse@abc-tekstil.test',
            'contact_phone' => '+90 212 555 00 00', 'platform_note' => 'Pilot müşteri']);
    }

    private function tenantBySlug(string $slug): object
    {
        return DB::connection('pgsql_platform')->table('tenants')->where('slug', $slug)->sole();
    }

    /**
     * Opens an invitation link on the panel host, as a browser would.
     */
    private function open(string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->get($url);
    }

    public function test_platform_admin_creates_a_customer_with_an_invited_owner_atomically(): void
    {
        $this->asAdmin(recent: false)->post("{$this->host}/tenants", ['name' => 'X', 'slug' => 'x-co', 'owner_name' => 'X', 'owner_email' => 'x@x.test'])->assertRedirect(route('admin.confirm'));
        $this->assertSame(0, DB::connection('pgsql_platform')->table('tenants')->where('slug', 'x-co')->count(), 'Needs a recent password confirmation.');
        $siteOwnerBefore = app(SiteOwner::class)->tenantId();

        $r = $this->createViaPanel()->assertSessionHasNoErrors();
        $t = $this->tenantBySlug('abc-tekstil');
        $r->assertRedirect(route('admin.tenants.show', [$t->id, 'tab' => 'kullanicilar']));
        $url = (string) session('invitation.url');
        $this->assertMatchesRegularExpression('#^http://localhost/app/davet/[A-Za-z0-9]{64}\?expires=\d+&signature=[0-9a-f]{64}$#', $url);

        $this->assertSame(['active', '+90 212 555 00 00', null], [$t->status, $t->contact_phone, $t->plan]);
        $this->assertSame('Pilot müşteri', DB::connection('pgsql_platform')->table('platform_tenant_notes')->where('tenant_id', $t->id)->value('note'));
        $owner = User::query()->where('email', 'ayse@abc-tekstil.test')->sole();
        $this->assertNull($owner->activated_at, 'The owner sets their own password.');
        $this->assertFalse(Hash::check('a-very-long-admin-password', $owner->password));
        app(TenantContext::class)->run($t->id, function () use ($t, $owner) {
            $this->assertSame([['owner', 'invited']], DB::table('tenant_users')->where('user_id', $owner->id)->get(['role', 'status'])->map(fn ($m) => [$m->role, $m->status])->all());
            $this->assertSame(1, DB::table('tenant_settings')->count());
            $this->assertSame(0, DB::table('tenant_integrations')->count(), 'No integration is assumed.');
            $this->assertSame(1, DB::table('tenant_data_keys')->where('tenant_id', $t->id)->count());
            $this->assertSame('off', DB::table('tenant_settings')->value('sending_mode'));
            $actions = DB::table('audit_logs')->where('tenant_id', $t->id)->pluck('action')->all();
            $this->assertContains('tenant.created', $actions);
            $this->assertContains('tenant.owner.invited', $actions);
            $token = Str::between(session('invitation.url'), '/davet/', '?');
            $this->assertSame(0, DB::table('audit_logs')->whereRaw('metadata::text LIKE ?', ['%'.$token.'%'])->count(), 'Token never audited.');
            $this->assertSame(0, DB::table('user_invitations')->where('token_hash', $token)->count(), 'Only the hash is stored.');
            $this->assertSame(1, DB::table('user_invitations')->where('token_hash', hash('sha256', $token))->count());
        });
        $this->assertSame($siteOwnerBefore, app(SiteOwner::class)->tenantId(), 'Website ownership unchanged.');
        $this->assertSame(0, DB::table('tenant_users')->count(), 'No context: nothing visible.');

        $list = $this->asAdmin()->get("{$this->host}/tenants?q=ayse@abc")->assertOk();
        $list->assertSee('ABC Tekstil')->assertSee('ayse@abc-tekstil.test')->assertSee('0/4 hazır')->assertSee('davet bekliyor');
        $this->asAdmin()->get("{$this->host}/tenants?durum=suspended")->assertOk()->assertDontSee('ABC Tekstil');
        $detail = $this->asAdmin()->get("{$this->host}/tenants/{$t->id}?tab=entegrasyonlar")->assertOk();
        foreach (['Ticimax bağlantısı', 'İYS / ViA yapılandırması', 'E-posta gönderimi (Amazon SES)', 'Push bildirimleri (OneSignal)'] as $label) {
            $detail->assertSee($label);
        }
        $this->assertSame(4, substr_count((string) $detail->getContent(), 'Yapılandırılmadı'));
        $this->assertSame(0, DB::connection('pgsql_platform')->table('tenant_users')->where('tenant_id', $t->id)->where('status', 'active')->count(), 'The admin is never a member.');
    }

    public function test_the_platform_note_is_unreachable_for_tenant_roles_and_audited_without_its_text(): void
    {
        $this->createViaPanel()->assertSessionHasNoErrors();
        $t = $this->tenantBySlug('abc-tekstil');
        $this->assertFalse(Schema::hasColumn('tenants', 'platform_note'), 'Not on the tenant-readable row.');

        // The runtime role cannot read it, even inside the tenant's own context.
        foreach ([null, $t->id] as $context) {
            try {
                $context === null ? DB::select('SELECT note FROM platform_tenant_notes') : app(TenantContext::class)->run($context, fn () => DB::select('SELECT note FROM platform_tenant_notes'));
                $this->fail('saas_app must not read platform notes.');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0]);
            }
        }
        $this->asAdmin()->post("{$this->host}/tenants/{$t->id}/note", ['note' => 'Sözleşme Kasım sonunda yenilenecek.'])->assertSessionHasNoErrors();
        $this->asAdmin()->post("{$this->host}/tenants/{$t->id}/note", ['note' => 'api_key: dummy-not-a-real-key-0000000000000000000000'])->assertSessionHasErrors('note');
        $this->asAdmin()->post("{$this->host}/tenants/{$t->id}/note", ['note' => 'şifre = Gizli123'])->assertSessionHasErrors('note');
        $this->assertSame('Sözleşme Kasım sonunda yenilenecek.', DB::connection('pgsql_platform')->table('platform_tenant_notes')->where('tenant_id', $t->id)->value('note'));
        $this->asAdmin()->get("{$this->host}/tenants/{$t->id}"); // consumes the rejected input flashed for the form
        $this->asAdmin()->get("{$this->host}/tenants/{$t->id}")->assertOk()->assertSee('Sözleşme Kasım sonunda yenilenecek.');

        $notes = DB::connection('pgsql_platform')->table('audit_logs')->where('tenant_id', $t->id)->where('action', 'platform.tenant_note.changed')->pluck('metadata');
        $this->assertCount(2, $notes, 'Created with the tenant, then edited.');
        $this->assertStringNotContainsString('Sözleşme', $notes->implode(''));

        // The owner's panel never shows it.
        $this->open((string) session('invitation.url'));
        $owner = User::query()->where('email', 'ayse@abc-tekstil.test')->sole();
        $this->inTenantId($t->id, fn () => DB::table('tenant_users')->where('user_id', $owner->id)->update(['status' => 'active']));
        $owner->forceFill(['activated_at' => now()])->save();
        foreach (['/app/', '/app/settings', '/app/team'] as $page) {
            $this->actingAs($owner, 'web')->withSession([PanelTenant::SESSION_TENANT => $t->id, PanelRecentAuth::SESSION_KEY => time()])->get("http://localhost{$page}")
                ->assertOk()->assertDontSee('Sözleşme Kasım');
        }
    }

    private function inTenantId(string $id, \Closure $fn): mixed
    {
        return app(TenantContext::class)->run($id, $fn);
    }

    public function test_provisioning_is_idempotent_validated_and_rolls_back_on_failure(): void
    {
        $this->createViaPanel()->assertSessionHasNoErrors();
        $this->createViaPanel()->assertSessionHasNoErrors()->assertSessionHas('status', fn ($s) => str_contains($s, 'zaten'))->assertSessionMissing('invitation');
        $this->assertSame(1, DB::connection('pgsql_platform')->table('tenants')->where('slug', 'abc-tekstil')->count());
        $this->assertSame(1, User::query()->where('email', 'ayse@abc-tekstil.test')->count());
        $this->assertSame(1, DB::connection('pgsql_platform')->table('tenant_users')->where('tenant_id', $this->tenantBySlug('abc-tekstil')->id)->count());

        $this->createViaPanel(['owner_email' => 'baskasi@example.test'])->assertSessionHasErrors('slug');
        foreach (['ABC', 'abc_tekstil', '-abc', 'abc-', 'a', 'admin', 'app', 'ab--c'] as $bad) {
            $this->createViaPanel(['slug' => $bad, 'owner_email' => 'n@n.test'])->assertSessionHasErrors('slug');
        }
        $this->createViaPanel(['slug' => 'yeni-firma', 'owner_email' => 'gecersiz'])->assertSessionHasErrors('owner_email');

        // A failure after tenant, settings, user and membership were written leaves nothing behind.
        TenantUser::created(fn () => throw new \RuntimeException('step failed'));
        try {
            app(TenantProvisioner::class)->provision(['name' => 'Yarım', 'slug' => 'yarim-firma', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'currency' => 'TRY', 'locale' => 'tr'],
                ['email' => 'yarim@example.test', 'name' => 'Yarım'], Actor::system());
            $this->fail('Expected failure.');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, DB::connection('pgsql_platform')->table('tenants')->where('slug', 'yarim-firma')->count());
        $this->assertSame(0, User::query()->where('email', 'yarim@example.test')->count());
        $this->assertSame(1, DB::connection('pgsql_platform')->table('user_invitations')->count(), 'Only the first tenant\'s invitation exists.');
    }

    public function test_the_owner_activates_with_their_own_password_through_a_single_use_link(): void
    {
        $this->createViaPanel();
        $url = (string) session('invitation.url');
        $t = $this->tenantBySlug('abc-tekstil');

        // Before activation: no sign-in with any password.
        $this->open('http://localhost/app/login');
        $this->post('http://localhost/app/login', ['email' => 'ayse@abc-tekstil.test', 'password' => 'whatever-password-1'])->assertSessionHasErrors('email');
        // Tampered or unsigned links are refused before any lookup.
        $this->open(Str::before($url, '?'))->assertForbidden();
        $this->open(preg_replace('/signature=[0-9a-f]{64}/', 'signature='.str_repeat('0', 64), $url))->assertForbidden();

        $this->open($url)->assertOk()->assertSee('ABC Tekstil')->assertSee('ayse@abc-tekstil.test')->assertSee('Şifre belirleyin');
        $this->post($url, ['password' => 'kisa', 'password_confirmation' => 'kisa'])->assertSessionHasErrors('password');
        $this->post($url, ['password' => 'Ayse-kendi-sifresi-2026', 'password_confirmation' => 'Ayse-kendi-sifresi-2026'])->assertRedirect(route('panel.dashboard'));
        $this->assertAuthenticated('web');
        $this->get('http://localhost/app/')->assertOk()->assertSee('Başlangıç / Kurulum Durumu')->assertSee('0/4 hazır');

        $owner = User::query()->where('email', 'ayse@abc-tekstil.test')->sole();
        $this->assertNotNull($owner->activated_at);
        $this->assertTrue(Hash::check('Ayse-kendi-sifresi-2026', $owner->password));
        $this->assertNull($owner->email_verified_at, 'A manually handed-over link does not prove the address.');
        $this->assertSame('active', DB::connection('pgsql_platform')->table('tenant_users')->where('tenant_id', $t->id)->value('status'));

        // Replay: refused, password unchanged.
        $this->open($url)->assertStatus(410);
        $this->post($url, ['password' => 'Saldirgan-sifresi-2026', 'password_confirmation' => 'Saldirgan-sifresi-2026'])->assertStatus(410);
        $this->assertTrue(Hash::check('Ayse-kendi-sifresi-2026', $owner->refresh()->password));

        // Normal sign-in now works.
        $this->open('http://localhost/app/login');
        $this->post('http://localhost/app/login', ['email' => 'ayse@abc-tekstil.test', 'password' => 'Ayse-kendi-sifresi-2026'])->assertRedirect(route('panel.dashboard'));
    }

    public function test_expired_and_replaced_links_stop_working(): void
    {
        $this->createViaPanel();
        $first = (string) session('invitation.url');
        $t = $this->tenantBySlug('abc-tekstil');
        $member = DB::connection('pgsql_platform')->table('tenant_users')->where('tenant_id', $t->id)->value('id');

        $this->asAdmin()->post("{$this->host}/tenants/{$t->id}/members/{$member}/invitation")->assertSessionHasNoErrors();
        $second = (string) session('invitation.url');
        $this->assertNotSame($first, $second);
        $this->open($first)->assertStatus(410);
        $this->open($second)->assertOk();

        $this->travel((int) config('platform.invitations.ttl_hours') + 1)->hours();
        $this->open($second)->assertForbidden();
    }

    public function test_an_existing_account_must_sign_in_as_itself_to_accept(): void
    {
        [, $igsOwner] = $this->createTenant('igs-test');
        $this->createViaPanel(['owner_email' => $igsOwner->email]);
        $url = (string) session('invitation.url');
        $t = $this->tenantBySlug('abc-tekstil');

        // Holding the link is not enough: no password form, nothing changes.
        $this->open($url)->assertOk()->assertDontSee('Şifre belirleyin')->assertSee('mevcut bir hesaba ait');
        $this->post($url, ['password' => 'Ele-gecirme-denemesi-1', 'password_confirmation' => 'Ele-gecirme-denemesi-1'])->assertRedirect(route('panel.login'));
        $this->assertTrue(Hash::check(self::PASSWORD, $igsOwner->refresh()->password));
        $this->assertSame('invited', DB::connection('pgsql_platform')->table('tenant_users')->where('tenant_id', $t->id)->value('status'));

        // Another signed-in user cannot accept it either.
        [, $other] = $this->createTenant('baska');
        $this->actingAs($other, 'web')->post($url)->assertRedirect(route('panel.login'));

        $this->app['auth']->forgetGuards();
        $this->actingAs($igsOwner, 'web')->get($url)->assertOk()->assertSee('Daveti kabul et');
        $this->actingAs($igsOwner, 'web')->post($url)->assertRedirect(route('panel.dashboard'));
        $this->assertSame('active', DB::connection('pgsql_platform')->table('tenant_users')->where('tenant_id', $t->id)->value('status'));
        $this->assertSame($t->id, session(PanelTenant::SESSION_TENANT));
    }

    public function test_members_are_invited_reassigned_and_disabled_with_audit_and_an_owner_always_remains(): void
    {
        [$tenant, $owner] = $this->createTenant('igs-test');
        $members = fn () => collect(DB::connection('pgsql_platform')->select('SELECT * FROM app_platform_tenant_members(?)', [$tenant->id]));

        $this->asAdmin()->post("{$this->host}/tenants/{$tenant->id}/members", ['email' => 'Editor@IGS.test', 'name' => 'Editör', 'role' => 'marketer'])->assertSessionHasNoErrors();
        $this->assertNotEmpty(session('invitation.url'));
        $editor = $members()->firstWhere('email', 'editor@igs.test');
        $this->assertSame(['marketer', 'invited', false], [$editor->role, $editor->status, $editor->activated]);
        $this->asAdmin()->post("{$this->host}/tenants/{$tenant->id}/members", ['email' => 'editor@igs.test', 'role' => 'viewer'])->assertSessionHasErrors('member');
        $this->asAdmin()->post("{$this->host}/tenants/{$tenant->id}/members", ['email' => 'x@igs.test', 'role' => 'superadmin'])->assertSessionHasErrors('role');

        $this->asAdmin()->post("{$this->host}/tenants/{$tenant->id}/members/{$editor->membership_id}/role", ['role' => 'approver'])->assertSessionHasNoErrors();
        $this->assertSame('approver', $members()->firstWhere('email', 'editor@igs.test')->role);
        $this->asAdmin()->post("{$this->host}/tenants/{$tenant->id}/members/{$editor->membership_id}/status", ['status' => 'disabled'])->assertSessionHasNoErrors();
        $this->assertSame('disabled', $members()->firstWhere('email', 'editor@igs.test')->status);
        $this->asAdmin()->post("{$this->host}/tenants/{$tenant->id}/members/{$editor->membership_id}/status", ['status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame('invited', $members()->firstWhere('email', 'editor@igs.test')->status, 'Never activates an invitation nobody accepted.');

        $ownerRow = $members()->firstWhere('email', $owner->email);
        $this->asAdmin()->post("{$this->host}/tenants/{$tenant->id}/members/{$ownerRow->membership_id}/status", ['status' => 'disabled'])->assertSessionHasErrors('member');
        $this->asAdmin()->post("{$this->host}/tenants/{$tenant->id}/members/{$ownerRow->membership_id}/role", ['role' => 'viewer'])->assertSessionHasErrors('member');
        $this->assertSame(['owner', 'active'], [$members()->firstWhere('email', $owner->email)->role, $members()->firstWhere('email', $owner->email)->status]);

        // A membership of another tenant cannot be targeted through this tenant's URL.
        [$other] = $this->createTenant('abc-tekstil-test');
        $foreign = collect(DB::connection('pgsql_platform')->select('SELECT * FROM app_platform_tenant_members(?)', [$other->id]))->first();
        $this->asAdmin()->post("{$this->host}/tenants/{$tenant->id}/members/{$foreign->membership_id}/status", ['status' => 'disabled'])->assertSessionHasErrors('member');
        $this->assertSame('active', DB::connection('pgsql_platform')->table('tenant_users')->where('id', $foreign->membership_id)->value('status'));

        $actions = DB::connection('pgsql_platform')->table('audit_logs')->where('tenant_id', $tenant->id)->where('actor_type', 'platform_admin')->pluck('action')->all();
        foreach (['tenant.user.invited', 'tenant.role.changed', 'tenant.membership.disabled', 'tenant.membership.enabled'] as $action) {
            $this->assertContains($action, $actions);
        }
        $this->asAdmin()->get("{$this->host}/tenants/{$tenant->id}?tab=audit")->assertOk()->assertSee('tenant.role.changed');
    }

    public function test_a_suspended_tenant_is_refused_everywhere_and_its_data_is_kept(): void
    {
        [$tenant, $owner] = $this->createTenant('abc-tekstil-test');
        $customer = $this->createCustomer($tenant, 'musteri@abc.test');
        $token = $this->tokenFor($owner, $tenant);
        $panel = fn () => $this->actingAs($owner, 'web')->withSession([PanelTenant::SESSION_TENANT => $tenant->id, PanelRecentAuth::SESSION_KEY => time()]);
        $panel()->get('http://localhost/app/')->assertOk();

        $this->asAdmin()->post("{$this->host}/tenants/{$tenant->id}/status", ['action' => 'suspend', 'reason' => 'Ödeme bekleniyor', 'confirm_slug' => 'yanlis'])->assertSessionHasErrors('confirm_slug');
        $this->asAdmin()->post("{$this->host}/tenants/{$tenant->id}/status", ['action' => 'suspend', 'reason' => '', 'confirm_slug' => 'abc-tekstil-test'])->assertSessionHasErrors();
        $this->asAdmin()->post("{$this->host}/tenants/{$tenant->id}/status", ['action' => 'suspend', 'reason' => 'Ödeme bekleniyor', 'confirm_slug' => 'abc-tekstil-test'])->assertSessionHasNoErrors();

        $this->app['auth']->forgetGuards();
        $panel()->get('http://localhost/app/')->assertForbidden();
        $panel()->get('http://localhost/app/campaigns')->assertForbidden();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->api('GET', 'http://localhost/api/v1/customers', $token)->assertForbidden();
        $this->assertNotContains($tenant->id, app(TenantDirectory::class)->activeTenantIds(), 'Scheduler fan-out skips it (campaigns, automations, sync).');
        $this->assertNull(app(TenantDirectory::class)->idBySlug('abc-tekstil-test'), 'Console --tenant and webhooks refuse it.');
        $this->inTenant($tenant, fn () => $this->assertSame(1, DB::table('customers')->where('id', $customer->id)->count(), 'Data is kept.'));
        $this->asAdmin()->get("{$this->host}/tenants?durum=suspended")->assertOk()->assertSee('Abc-Tekstil-Test');

        $this->asAdmin()->post("{$this->host}/tenants/{$tenant->id}/status", ['action' => 'reactivate', 'confirm_slug' => 'abc-tekstil-test'])->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();
        $panel()->get('http://localhost/app/')->assertOk();
        $actions = DB::connection('pgsql_platform')->table('audit_logs')->where('tenant_id', $tenant->id)->pluck('action')->all();
        $this->assertContains('tenant.suspended', $actions);
        $this->assertContains('tenant.reactivated', $actions);
    }

    public function test_the_website_owner_tenant_cannot_be_suspended_and_new_tenants_never_take_the_site(): void
    {
        config(['app.url' => 'https://pratiktools.test']);
        [$site] = $this->createTenant('pratiktools');
        $this->artisan('site:setup', ['--tenant' => 'pratiktools'])->assertSuccessful();
        $this->asAdmin()->post("{$this->host}/tenants/{$site->id}/status", ['action' => 'suspend', 'reason' => 'deneme', 'confirm_slug' => 'pratiktools'])->assertSessionHasErrors('action');
        $this->assertSame('active', DB::connection('pgsql_platform')->table('tenants')->where('id', $site->id)->value('status'));

        $this->createViaPanel()->assertSessionHasNoErrors();
        $this->assertSame($site->id, app(SiteOwner::class)->tenantId());
        $this->asAdmin()->get("{$this->host}/tenants")->assertOk()->assertSee('pratiktools.com sahibi');
    }

    public function test_cli_and_panel_share_the_provisioning_service(): void
    {
        $this->artisan('tenant:create', ['name' => 'XYZ E-Ticaret', 'slug' => 'xyz-eticaret', '--owner-email' => 'owner@xyz.test', '--owner-name' => 'XYZ Owner', '--invite' => true])
            ->expectsOutputToContain('Owner invitation created')->doesntExpectOutputToContain('/app/davet/')->assertSuccessful();
        $this->artisan('tenant:create', ['name' => 'XYZ E-Ticaret', 'slug' => 'xyz-eticaret', '--owner-email' => 'owner@xyz.test', '--invite' => true])
            ->expectsOutputToContain('nothing changed')->assertSuccessful();
        $this->artisan('tenant:create', ['name' => 'Başka', 'slug' => 'xyz-eticaret', '--owner-email' => 'other@xyz.test', '--invite' => true])->assertFailed();
        $this->assertSame(1, DB::connection('pgsql_platform')->table('tenants')->where('slug', 'xyz-eticaret')->count());

        $this->expectException(ProvisioningError::class);
        app(TenantProvisioner::class)->provision(['name' => 'X', 'slug' => 'admin', 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'currency' => 'TRY', 'locale' => 'tr'],
            ['email' => 'x@x.test', 'name' => 'X']);
    }

    public function test_no_impersonation_path_exists(): void
    {
        [$tenant] = $this->createTenant('igs-test');
        $this->asAdmin()->get("{$this->host}/tenants/{$tenant->id}")->assertOk()->assertDontSee('olarak görüntüle');
        foreach (['impersonate', 'login-as', 'view-as'] as $path) {
            $this->asAdmin()->post("{$this->host}/tenants/{$tenant->id}/{$path}")->assertNotFound();
        }
        // A platform session gives no access to the tenant panel.
        $this->asAdmin()->get('http://localhost/app/')->assertRedirect(route('panel.login'));
    }
}
