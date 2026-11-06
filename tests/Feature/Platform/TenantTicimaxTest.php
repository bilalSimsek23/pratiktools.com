<?php

namespace Tests\Feature\Platform;

use App\Audit\Actor;
use App\Commerce\TicimaxIntegrations;
use App\Http\Middleware\EnsurePlatformTwoFactor;
use App\Http\Middleware\PlatformRecentAuth;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Security\Vault\SecretVault;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use PragmaRX\Google2FA\Google2FA;
use SoapFault;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithTicimax;
use Tests\Support\FakeTicimax;
use Tests\TestCase;

/**
 * Super Admin › Müşteriler › Entegrasyonlar › Ticimax: the member code is
 * write-only (vault, never shown, flashed, logged or audited), changes and
 * tests need a recent password and the second factor, everything runs inside
 * the tenant's own context, and the connection test is the existing
 * read-only ConnectionTester.
 */
final class TenantTicimaxTest extends TestCase
{
    use InteractsWithTenants, InteractsWithTicimax;

    private const SECRET = 'IGS-UYEKODU-PLAINTEXT-7f3a9c';

    private const NEW_SECRET = 'IGS-UYEKODU-ROTATED-41be20';

    private const READS = ['SelectSiparisDurumlari', 'SelectUyeler', 'SelectSiparis'];

    private string $host;

    private PlatformAdmin $admin;

    /** @var list<string> */
    private array $logs = [];

    private string $output = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTicimax();
        $this->host = 'http://'.config('platform.admin_domain');
        config(['app.url' => 'http://localhost', 'site.owner_tenant' => null]);
        $admin = new PlatformAdmin(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'a-very-long-admin-password', 'status' => 'active']);
        $admin->setConnection('pgsql_owner')->save();
        $this->admin = PlatformAdmin::query()->findOrFail($admin->id);
        $this->admin->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(32), 'two_factor_confirmed_at' => now()])->save();
        $this->app['events']->listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = $e->message.' '.json_encode($e->context);
        });
    }

    private function asAdmin(bool $recent = true, bool $twoFactor = true): self
    {
        return $this->actingAs($this->admin, 'platform')->withSession(array_filter([EnsurePlatformTwoFactor::SESSION_KEY => $twoFactor ? $this->admin->id : null,
            PlatformRecentAuth::SESSION_KEY => $recent ? time() : 0], fn ($v) => $v !== null));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function save(Tenant $tenant, array $input, bool $recent = true, bool $twoFactor = true): TestResponse
    {
        return $this->asAdmin($recent, $twoFactor)->from($this->page($tenant))->post("{$this->page($tenant)}", $input + [
            'service_url' => "https://{$tenant->slug}.example.com/Servis/", 'storefront_url' => "https://{$tenant->slug}.example.com",
        ]);
    }

    private function verify(Tenant $tenant, bool $recent = true): TestResponse
    {
        return $this->asAdmin($recent)->from($this->page($tenant))->post("{$this->page($tenant)}/test");
    }

    private function page(Tenant $tenant): string
    {
        return "{$this->host}/tenants/{$tenant->id}/integrations/ticimax";
    }

    private function integration(Tenant $tenant): ?object
    {
        return $this->inTenant($tenant, fn () => DB::table('tenant_integrations')->where('provider', 'ticimax')->first());
    }

    /**
     * @return Collection<int, object>
     */
    private function audits(string $action): Collection
    {
        return DB::connection('pgsql_platform')->table('audit_logs')->where('action', $action)->orderBy('created_at')->get(['tenant_id', 'actor_type', 'actor_id', 'resource_id', 'metadata']);
    }

    /**
     * Nowhere but the vault ciphertext: not the integration row in plaintext,
     * the audit trail, the logs, the session or the given responses.
     */
    private function assertNowhere(string $secret, TestResponse ...$responses): void
    {
        $everything = DB::connection('pgsql_platform')->table('audit_logs')->get(['action', 'resource_type', 'resource_id', 'actor_id', 'metadata'])->toJson()
            .implode("\n", $this->logs).json_encode(session()->all());
        foreach (Tenant::query()->get() as $tenant) {
            $everything .= $this->inTenant($tenant, fn () => DB::table('tenant_integrations')->get()->toJson().DB::table('integration_errors')->get()->toJson());
        }
        foreach ($responses as $r) {
            $everything .= $r->getContent().json_encode($r->headers->all());
        }
        $this->assertStringNotContainsString($secret, $everything);
    }

    public function test_the_first_save_stores_the_member_code_in_the_vault_only(): void
    {
        [$igs] = $this->createTenant('igs');
        $this->asAdmin()->get("{$this->host}/tenants/{$igs->id}?tab=entegrasyonlar")->assertOk()->assertSee($this->page($igs), false);
        $this->asAdmin()->get($this->page($igs))->assertOk()->assertSee('Yapılandırılmadı');

        $saved = $this->save($igs, ['secret' => self::SECRET])->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Ticimax ayarları ve yetki kodu kaydedildi. Bağlantıyı test edin.');

        $row = $this->integration($igs);
        $this->assertSame(['commerce', 'pending', null, null], [$row->capability, $row->status, $row->last_verified_at, $row->last_error]);
        $this->assertSame(['service_url' => 'https://igs.example.com/Servis/', 'storefront_url' => 'https://igs.example.com'], json_decode($row->settings, true));
        $this->assertNotNull($row->encrypted_secret);
        $this->assertNotNull($row->secret_fingerprint);
        $this->assertSame(self::SECRET, $this->reveal($igs), 'The vault opens it again.');

        $created = $this->audits('integration.secret_created')->sole();
        $this->assertSame([$igs->id, 'platform_admin', $this->admin->id, $row->id], [$created->tenant_id, $created->actor_type, $created->actor_id, $created->resource_id]);
        $this->assertSame(['fingerprint' => $row->secret_fingerprint], json_decode($created->metadata, true));
        $this->assertSame('platform_admin', $this->audits('integration.created')->sole()->actor_type);

        $page = $this->asAdmin()->get($this->page($igs))->assertOk()->assertSee('Yetki kodu kayıtlı')->assertSee('Test bekliyor')->assertSee('Platform yöneticisi (Root)')
            ->assertSee('https://igs.example.com/Servis/')->assertSee('Bağlantıyı Test Et');
        $this->assertDoesNotMatchRegularExpression('/name="secret"[^>]*value=/', (string) $page->getContent(), 'The code field is never filled in.');
        $this->assertNowhere(self::SECRET, $saved, $page);
    }

    public function test_a_new_code_replaces_the_old_one_and_needs_a_new_test(): void
    {
        [$igs] = $this->createTenant('igs');
        $this->connectTicimax($igs, $this->shop(self::SECRET), secret: self::SECRET);
        $this->verify($igs)->assertSessionHas('status', 'Bağlantı testi başarılı.');
        $before = $this->integration($igs);
        $this->assertSame('connected', $before->status);
        $this->assertNotNull($before->last_verified_at);

        $saved = $this->save($igs, ['secret' => self::NEW_SECRET])->assertSessionHasNoErrors();

        $after = $this->integration($igs);
        $this->assertSame(['pending', null, null], [$after->status, $after->last_verified_at, $after->last_error]);
        $this->assertNotSame($before->encrypted_secret, $after->encrypted_secret);
        $this->assertNotSame($before->secret_fingerprint, $after->secret_fingerprint);
        $this->assertSame(self::NEW_SECRET, $this->reveal($igs));
        $changed = $this->audits('integration.secret_changed')->sole();
        $this->assertSame(['platform_admin', $this->admin->id], [$changed->actor_type, $changed->actor_id]);
        $this->assertEquals(['previous_fingerprint' => $before->secret_fingerprint, 'fingerprint' => $after->secret_fingerprint], json_decode($changed->metadata, true));
        $this->assertCount(0, $this->audits('integration.settings_changed'), 'Same URLs: no settings change.');

        // The old code no longer works at the (fake) store; the new one does.
        $this->store($igs)->validKey = self::NEW_SECRET;
        $this->verify($igs)->assertSessionHas('status', 'Bağlantı testi başarılı.');
        $this->assertSame('connected', $this->integration($igs)->status);
        $this->assertNowhere(self::SECRET);
        $this->assertNowhere(self::NEW_SECRET, $saved);
    }

    public function test_an_empty_code_keeps_the_stored_one_and_only_changes_the_urls(): void
    {
        [$igs] = $this->createTenant('igs');
        $this->connectTicimax($igs, $this->shop(self::SECRET), secret: self::SECRET);
        $this->verify($igs);
        $before = $this->integration($igs);

        $this->save($igs, ['secret' => '', 'storefront_url' => 'https://www.igs.example.com/'])->assertSessionHasNoErrors()->assertSessionHas('status', 'Ticimax ayarları kaydedildi.');
        $after = $this->integration($igs);
        $this->assertSame([$before->encrypted_secret, $before->secret_fingerprint], [$after->encrypted_secret, $after->secret_fingerprint]);
        $this->assertSame('https://www.igs.example.com', json_decode($after->settings, true)['storefront_url']);
        $this->assertSame(['connected', $before->last_verified_at], [$after->status, $after->last_verified_at], 'Another storefront does not invalidate the test.');
        $this->assertCount(0, $this->audits('integration.secret_changed'));
        $this->assertEquals(['changed' => ['storefront_url'], 'from' => ['storefront_url' => 'https://igs.example.com'], 'to' => ['storefront_url' => 'https://www.igs.example.com']],
            json_decode($this->audits('integration.settings_changed')->sole()->metadata, true));

        // Another service endpoint does: the code stays, the test is due again.
        $this->fakeStores['https://yeni.igs.example.com/Servis/'] = $this->shop(self::SECRET);
        $this->save($igs, ['service_url' => 'https://yeni.igs.example.com/Servis'])->assertSessionHasNoErrors();
        $after = $this->integration($igs);
        $this->assertSame([$before->encrypted_secret, 'pending', null], [$after->encrypted_secret, $after->status, $after->last_verified_at]);
        $this->assertSame('https://yeni.igs.example.com/Servis/', json_decode($after->settings, true)['service_url']);
        $this->verify($igs)->assertSessionHas('status');
        $this->assertSame('connected', $this->integration($igs)->status);
    }

    public function test_invalid_input_never_echoes_the_code(): void
    {
        [$igs] = $this->createTenant('igs');

        $this->save($igs, ['service_url' => 'not a url', 'secret' => self::SECRET])->assertSessionHasErrors('service_url')->assertSessionMissing('_old_input.secret');
        $this->save($igs, ['service_url' => 'http://igs.example.com/Servis/', 'secret' => self::SECRET])->assertSessionHasErrors('service_url');
        $this->save($igs, ['secret' => 'Zq9shrt'])->assertSessionHasErrors('secret');
        $this->save($igs, ['secret' => str_repeat('x', 513)])->assertSessionHasErrors('secret');
        $this->save($igs, ['secret' => ''])->assertSessionHasErrors(['ticimax' => 'İlk kurulumda Ticimax yetki kodu (UyeKodu) zorunludur.']);
        $this->assertNull(session()->getOldInput('secret'));
        // A private address passes the URL rule but not the outbound guard.
        $unsafe = $this->save($igs, ['service_url' => 'https://127.0.0.1/Servis/', 'secret' => self::SECRET])->assertSessionHasErrors('ticimax');
        $this->assertNull(session()->getOldInput('secret'));
        $this->assertSame('https://127.0.0.1/Servis/', session()->getOldInput('service_url'));

        $this->assertNull($this->integration($igs));
        $this->assertCount(0, $this->audits('integration.secret_created'));
        $page = $this->asAdmin()->withSession(['_old_input' => ['service_url' => 'x']])->get($this->page($igs))->assertOk();
        $this->assertNowhere(self::SECRET, $unsafe, $page);
        $this->assertNowhere('Zq9shrt');
    }

    public function test_the_connection_test_reads_only_and_reports_connected_action_required_and_error(): void
    {
        [$igs] = $this->createTenant('igs');
        $this->connectTicimax($igs, $this->shop(self::SECRET), secret: self::SECRET, status: 'pending');

        $ok = $this->verify($igs)->assertSessionHasNoErrors()->assertSessionHas('status', 'Bağlantı testi başarılı.');
        $row = $this->integration($igs);
        $this->assertSame(['connected', null], [$row->status, $row->last_error]);
        $this->assertNotNull($verifiedAt = $row->last_verified_at);
        $this->assertSame(self::READS, array_values(array_unique(array_column($this->store($igs)->calls, 'operation'))), 'Only the three reads of ConnectionTester.');
        $verified = $this->audits('integration.verified')->sole();
        $this->assertSame(['platform_admin', $this->admin->id], [$verified->actor_type, $verified->actor_id]);
        $this->assertSame('connected', json_decode($verified->metadata, true)['result']);
        $this->asAdmin()->get($this->page($igs))->assertSee('b-ok">Bağlı', false);

        // A rejected code needs a person; the last good verification stays.
        $this->store($igs)->validKey = 'another-code';
        $this->verify($igs)->assertSessionHasErrors('ticimax_test');
        $row = $this->integration($igs);
        $this->assertSame(['action_required', 'Ticimax rejected the authorization code.', $verifiedAt], [$row->status, $row->last_error, $row->last_verified_at]);
        $this->asAdmin()->get($this->page($igs))->assertSee('b-warn">İşlem gerekli', false)->assertSee('Ticimax rejected the authorization code.');

        // Unreachable: an error that may resolve by itself; the fault text is not kept.
        $this->store($igs)->validKey = self::SECRET;
        $this->store($igs)->failOn('SelectSiparisDurumlari', new SoapFault('HTTP', 'Could not connect to host '.self::SECRET), times: 10);
        $this->verify($igs)->assertSessionHasErrors('ticimax_test');
        $row = $this->integration($igs);
        $this->assertSame(['error', 'Ticimax was unreachable or timed out (SoapFault).', $verifiedAt], [$row->status, $row->last_error, $row->last_verified_at]);
        $this->asAdmin()->get($this->page($igs))->assertSee('b-bad">Hata', false);

        $this->assertSame([], array_diff(array_column($this->store($igs)->calls, 'operation'), self::READS), 'No write operation was ever called.');
        $this->assertSame(0, $this->inTenant($igs, fn () => DB::table('integration_sync_runs')->count()), 'No sync started.');
        $this->assertNowhere(self::SECRET, $ok);
    }

    public function test_the_connection_test_needs_a_stored_code_and_is_rate_limited(): void
    {
        [$igs] = $this->createTenant('igs');
        $this->verify($igs)->assertSessionHasErrors(['ticimax_test' => 'Bu müşteri için Ticimax entegrasyonu yok.']);
        $this->asAdmin()->get($this->page($igs))->assertDontSee('Bağlantıyı Test Et');

        $this->connectTicimax($igs, $this->shop(self::SECRET), secret: self::SECRET);
        // Six a minute, the failed one above included.
        for ($i = 0; $i < 5; $i++) {
            $this->verify($igs)->assertRedirect();
        }
        $this->verify($igs)->assertStatus(429);
    }

    public function test_one_tenant_never_reaches_another_tenants_integration(): void
    {
        [$igs] = $this->createTenant('igs');
        [$other] = $this->createTenant('baska-firma');
        $this->connectTicimax($other, $this->shop('other-tenant-code-1234'), secret: 'other-tenant-code-1234');
        $theirs = $this->integration($other);

        $this->asAdmin()->get($this->page($igs))->assertOk()->assertSee('Yapılandırılmadı')->assertDontSee('baska-firma.example.com');
        $this->save($igs, ['secret' => self::SECRET])->assertSessionHasNoErrors();
        $this->verify($igs);

        $this->assertEquals($theirs, $this->integration($other), 'The other tenant is untouched.');
        $this->assertSame([], $this->store($other)->calls, 'Its store was never called.');
        $this->assertSame(1, $this->inTenant($igs, fn () => DB::table('tenant_integrations')->where('provider', 'ticimax')->count()));
        $this->asAdmin()->get($this->page($other))->assertOk()->assertSee('baska-firma.example.com')->assertDontSee('igs.example.com/Servis');
        $this->asAdmin()->get("{$this->host}/tenants/00000000-0000-7000-8000-000000000000/integrations/ticimax")->assertNotFound();
        $this->asAdmin()->post("{$this->host}/tenants/00000000-0000-7000-8000-000000000000/integrations/ticimax", ['service_url' => 'https://x.example.com', 'storefront_url' => 'https://x.example.com', 'secret' => self::SECRET])
            ->assertNotFound();
        $this->assertNowhere('other-tenant-code-1234');
    }

    public function test_rls_keeps_the_integration_closed_outside_the_tenant(): void
    {
        [$igs] = $this->createTenant('igs');
        $this->save($igs, ['secret' => self::SECRET])->assertSessionHasNoErrors();

        // No tenant context: the runtime role sees nothing and cannot save.
        $this->assertSame(0, DB::table('tenant_integrations')->count());
        try {
            app(TicimaxIntegrations::class)->save('https://x.example.com/Servis/', 'https://x.example.com', self::SECRET, Actor::system());
            $this->fail('Saving without a tenant must fail.');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $e);
        }
        $this->assertSame(1, $this->inTenant($igs, fn () => DB::table('tenant_integrations')->count()));
        // The platform role reads status columns only, never the secret.
        $this->assertSame('ticimax', DB::connection('pgsql_platform')->table('tenant_integrations')->where('tenant_id', $igs->id)->value('provider'));
        $this->expectException(QueryException::class);
        DB::connection('pgsql_platform')->table('tenant_integrations')->value('encrypted_secret');
    }

    public function test_changes_and_tests_need_a_recent_password_and_the_second_factor(): void
    {
        [$igs, $owner] = $this->createTenant('igs');
        $this->connectTicimax($igs, $this->shop(self::SECRET), secret: self::SECRET, status: 'pending');

        $this->save($igs, ['secret' => self::NEW_SECRET], recent: false)->assertRedirect(route('admin.confirm'));
        $this->flushSession();
        $this->verify($igs, recent: false)->assertRedirect(route('admin.confirm'));
        $this->flushSession();
        $this->save($igs, ['secret' => self::NEW_SECRET], twoFactor: false)->assertRedirect(route('admin.two-factor.challenge'));
        $this->flushSession();
        $this->asAdmin(twoFactor: false)->get($this->page($igs))->assertRedirect(route('admin.two-factor.challenge'));
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get($this->page($igs))->assertRedirect(route('admin.login'));
        $this->post($this->page($igs), ['secret' => self::NEW_SECRET])->assertRedirect(route('admin.login'));
        // A tenant user (even its owner) has no platform session.
        $this->actingAs($owner, 'web')->post("{$this->page($igs)}/test")->assertRedirect(route('admin.login'));

        $row = $this->integration($igs);
        $this->assertSame('pending', $row->status);
        $this->assertSame(self::SECRET, $this->reveal($igs));
        $this->assertSame([], $this->store($igs)->calls);
        $this->assertCount(0, $this->audits('integration.secret_changed'));
    }

    public function test_the_command_keeps_working_through_the_shared_service(): void
    {
        [$igs] = $this->createTenant('igs');
        $args = ['--tenant' => 'igs', '--service-url' => 'https://igs.example.com/Servis', '--storefront-url' => 'https://igs.example.com/'];

        $this->assertSame(2, $this->connect($args, null), 'A new integration needs the code.');
        $this->assertStringContainsString('needs the member code', $this->output);
        $this->assertNull($this->integration($igs));
        $this->assertSame(2, $this->connect(['--service-url' => 'https://127.0.0.1/Servis/'] + $args, self::SECRET));
        $this->assertSame(2, $this->connect($args, ''), 'Empty STDIN.');

        $this->assertSame(0, $this->connect($args, self::SECRET));
        $row = $this->integration($igs);
        $this->assertSame(['pending', 'https://igs.example.com/Servis/', 'https://igs.example.com'], [$row->status, ...array_values(json_decode($row->settings, true))]);
        $this->assertSame(['console', 'ticimax:connect'], [$this->audits('integration.secret_created')->sole()->actor_type, $this->audits('integration.secret_created')->sole()->actor_id]);

        // Without --secret-stdin only the URLs change; the code stays.
        $this->assertSame(0, $this->connect(['--storefront-url' => 'https://www.igs.example.com'] + $args, null));
        $this->assertSame($row->encrypted_secret, $this->integration($igs)->encrypted_secret);
        $this->assertSame(self::SECRET, $this->reveal($igs));
        $this->assertCount(0, $this->audits('integration.secret_changed'));

        // The screen shows the command as the last change.
        $this->asAdmin()->get($this->page($igs))->assertSee('Sunucu komutu (ticimax:connect)')->assertSee('Yetki kodu kayıtlı');
        $this->assertNowhere(self::SECRET);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function connect(array $args, ?string $stdin): int
    {
        $input = new ArrayInput(['command' => 'ticimax:connect'] + $args + ($stdin === null ? [] : ['--secret-stdin' => true]));
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, (string) $stdin);
        rewind($stream);
        $input->setStream($stream);
        $output = new BufferedOutput;
        $code = Artisan::handle($input, $output);
        $this->output = $output->fetch();
        $this->assertStringNotContainsString(self::SECRET, $this->output);

        return $code;
    }

    /**
     * A store with one member and one order, so every sample read finds data.
     */
    private function shop(string $key): FakeTicimax
    {
        $shop = new FakeTicimax($key);
        $shop->members = [FakeTicimax::member(1, 'uye@example.com')];
        $shop->orders = [FakeTicimax::order(1, 1, 'uye@example.com', [FakeTicimax::orderItem(1, 5, 50)])];

        return $shop;
    }

    private function reveal(Tenant $tenant): string
    {
        return $this->inTenant($tenant, function () {
            $integration = TenantIntegration::query()->where('provider', 'ticimax')->sole();

            return app(SecretVault::class)->reveal($integration, 'test', Actor::system())->reveal();
        });
    }
}
