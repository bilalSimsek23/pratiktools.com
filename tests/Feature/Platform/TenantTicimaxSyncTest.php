<?php

namespace Tests\Feature\Platform;

use App\Commerce\Onboarding\TicimaxOnboarding;
use App\Http\Middleware\EnsurePlatformTwoFactor;
use App\Http\Middleware\PlatformRecentAuth;
use App\Jobs\AdvanceTicimaxOnboarding;
use App\Jobs\SyncCommerceEntity;
use App\Jobs\SyncConsent;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Tenancy\TenantRole;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use SoapFault;
use Tests\Concerns\DrainsSyncQueue;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithTicimax;
use Tests\Support\FakeTicimax;
use Tests\TestCase;

/**
 * Super Admin › Entegrasyonlar › Ticimax › Veri Senkronizasyonu: the whole
 * Ticimax data setup from the panel, no console. The panel only queues the
 * existing jobs; the steps run in order on the queue, stop at a failure,
 * continue from it without repeating finished steps, and only read from
 * Ticimax.
 */
final class TenantTicimaxSyncTest extends TestCase
{
    use DrainsSyncQueue, InteractsWithTenants, InteractsWithTicimax;

    private const SECRET = 'IGS-UYEKODU-PLAINTEXT-sync-55aa';

    private const READS = ['SelectSiparisDurumlari', 'SelectUyeler', 'SelectMailList', 'SelectKategori', 'SelectUrun', 'SelectSiparis', 'SelectSepet', 'SelectUyeIzinGecmisi'];

    private string $host;

    private PlatformAdmin $admin;

    private Tenant $igs;

    private TenantIntegration $integration;

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTicimax();
        Queue::fake();
        config(['consent.ticimax.history_start' => now()->subMonths(2)->toDateString()]);
        $this->host = 'http://'.config('platform.admin_domain');
        config(['app.url' => 'http://localhost', 'site.owner_tenant' => null]);
        $admin = new PlatformAdmin(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'a-very-long-admin-password', 'status' => 'active']);
        $admin->setConnection('pgsql_owner')->save();
        $this->admin = PlatformAdmin::query()->findOrFail($admin->id);
        $this->admin->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(32), 'two_factor_confirmed_at' => now()])->save();
        $this->app['events']->listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = $e->message.' '.json_encode($e->context);
        });

        [$this->igs] = $this->createTenant('igs');
        $this->integration = $this->connectTicimax($this->igs, $this->shop(), secret: self::SECRET, status: 'pending');
    }

    private function shop(): FakeTicimax
    {
        $shop = new FakeTicimax(self::SECRET);
        $shop->categories = [FakeTicimax::category(1, 0, 'Kadin'), FakeTicimax::category(2, 1, 'Elbise')];
        $shop->products = array_map(fn ($i) => FakeTicimax::product($i, [FakeTicimax::variant($i * 10, 'Siyah', 'M')], [2]), range(1, 3));
        $shop->members = [FakeTicimax::member(1, 'ayse@example.com'), FakeTicimax::member(2, 'fatma@example.com')];
        $shop->orders = [FakeTicimax::order(10, 1, 'ayse@example.com', [FakeTicimax::orderItem(100, 1, 10)])];
        $shop->carts = [FakeTicimax::cart('c1', 2, 'fatma@example.com', [FakeTicimax::cartItem('c1', 2, 20)], now()->subDay()->format('Y-m-d\TH:i:s'))];
        $shop->consentHistory = [FakeTicimax::consentChange(1, 1, 2, true, 'ayse@example.com', now()->subMonth()->format('Y-m-d\TH:i:s'))];

        return $shop;
    }

    private function asAdmin(bool $recent = true, bool $twoFactor = true): self
    {
        return $this->actingAs($this->admin, 'platform')->withSession(array_filter([EnsurePlatformTwoFactor::SESSION_KEY => $twoFactor ? $this->admin->id : null,
            PlatformRecentAuth::SESSION_KEY => $recent ? time() : 0], fn ($v) => $v !== null));
    }

    private function page(?Tenant $tenant = null): string
    {
        return "{$this->host}/tenants/".($tenant ?? $this->igs)->id.'/integrations/ticimax';
    }

    private function startOnboarding(bool $recent = true, bool $twoFactor = true): TestResponse
    {
        return $this->asAdmin($recent, $twoFactor)->from($this->page())->post("{$this->page()}/onboarding");
    }

    private function startStep(string $step, ?Tenant $tenant = null): TestResponse
    {
        return $this->asAdmin()->from($this->page($tenant))->post("{$this->page($tenant)}/sync/{$step}");
    }

    /**
     * @param  list<object>  $jobs
     * @return list<string>
     */
    private function steps(array $jobs): array
    {
        return array_values(array_filter(array_map(fn ($j) => match (true) {
            $j instanceof SyncCommerceEntity => $j->entity->value,
            $j instanceof SyncConsent => $j->kind,
            default => null,
        }, $jobs)));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function state(): array
    {
        return $this->inTenant($this->igs, fn () => app(TicimaxOnboarding::class)->steps(TenantIntegration::query()->findOrFail($this->integration->id)));
    }

    private function runsOf(string $entity): int
    {
        return $this->inTenant($this->igs, fn () => DB::table('integration_sync_runs')->where('entity', $entity)->count());
    }

    public function test_the_whole_setup_runs_from_one_button_in_order_on_the_queue(): void
    {
        $this->asAdmin()->get($this->page())->assertOk()->assertSee('Veri Senkronizasyonu')->assertSee('Kurulumu Başlat')->assertSee('Bağlantı bekliyor');

        $this->startOnboarding()->assertSessionHasNoErrors()->assertSessionHas('status');
        // The request only queued the first move; nothing ran in it.
        Queue::assertPushed(AdvanceTicimaxOnboarding::class, 1);
        Queue::assertNotPushed(SyncCommerceEntity::class);
        $this->assertSame([], $this->store($this->igs)->calls);

        // The browser is gone; the queue does the rest.
        $ran = $this->drainQueue();

        $this->assertSame(['categories', 'products', 'customers', 'orders', 'carts', 'consent_build', 'ticimax_history'], $this->steps($ran));
        $this->assertTrue(collect($ran)->filter(fn ($j) => ! $j instanceof AdvanceTicimaxOnboarding)->every(fn ($j) => $j->onboarding));
        $state = $this->state();
        $this->assertSame(array_fill_keys(TicimaxOnboarding::STEPS, true), array_map(fn ($s) => $s['done'], $state));
        $this->assertSame(array_fill_keys(TicimaxOnboarding::STEPS, 'succeeded'), array_map(fn ($s) => $s['state'], $state));
        $this->assertSame([], array_values(array_diff(array_unique(array_column($this->store($this->igs)->calls, 'operation')), self::READS)), 'Only read operations.');

        $this->inTenant($this->igs, function () {
            $this->assertSame([2, 3, 2, 1, 1], [DB::table('categories')->count(), DB::table('products')->count(), DB::table('customers')->where('is_guest', false)->count(),
                DB::table('orders')->count(), DB::table('carts')->count()]);
            $this->assertGreaterThan(0, DB::table('consent_recipients')->where('address_type', 'email')->count());
            $this->assertSame(1, DB::table('consents')->where('source_provider', 'TICIMAX')->whereNotNull('external_id')->count(), 'The history entry.');
            $i = DB::table('tenant_integrations')->where('id', $this->integration->id)->first();
            $this->assertSame('connected', $i->status);
            $onboarding = json_decode($i->settings, true)['onboarding'];
            $this->assertFalse($onboarding['active']);
            $this->assertNotNull($onboarding['completed_at']);
            $this->assertSame(['https://igs.example.com/Servis/', 'https://igs.example.com'], [json_decode($i->settings, true)['service_url'], json_decode($i->settings, true)['storefront_url']], 'URLs untouched.');
            $this->assertSame(0, DB::table('consents')->whereIn('source_provider', ['IYS', 'VIA', 'IYS_RECONCILIATION'])->count(), 'No official consent is created.');
        });

        $this->asAdmin()->get($this->page())->assertOk()->assertSee('b-ok">Hazır', false)->assertDontSee('Kurulumu Başlat');
        $this->asAdmin()->get("{$this->host}/tenants/{$this->igs->id}?tab=entegrasyonlar")->assertOk()->assertSee('Ticimax Hazırlık')->assertSee('b-ok">Hazır', false)
            ->assertSee('Ticimax izin geçmişi');

        // Started again: nothing is repeated.
        $calls = count($this->store($this->igs)->calls);
        $this->startOnboarding()->assertSessionHasNoErrors();
        $this->assertSame([], $this->steps($this->drainQueue()));
        $this->assertSame($calls, count($this->store($this->igs)->calls));
        $this->assertNowhere(self::SECRET);
    }

    public function test_a_failed_step_stops_the_chain_and_it_continues_from_that_step(): void
    {
        $this->store($this->igs)->failOn('SelectUrun', new SoapFault('Server', 'Object reference not set to an instance of an object.'));
        $this->startOnboarding();

        $ran = $this->drainQueue();

        $this->assertSame(['categories', 'products'], $this->steps($ran), 'Stopped at the failed step.');
        $state = $this->state();
        $this->assertSame(['succeeded', 'failed', 'not_started'], [$state['categories']['state'], $state['products']['state'], $state['customers']['state']]);
        $page = $this->asAdmin()->get($this->page())->assertOk()->assertSee('b-bad">Müdahale gerekli', false)->assertSee('Kuruluma başarısız adımdan devam et')
            ->assertSee('Ticimax request failed (SoapFault).')->assertSee('Yeniden Dene');
        $this->asAdmin()->get("{$this->host}/tenants/{$this->igs->id}?tab=entegrasyonlar")->assertSee('Müdahale gerekli')->assertSee('❌');

        // Continue: categories (done) is not run again.
        $this->startOnboarding()->assertSessionHasNoErrors();
        $ran = $this->drainQueue();

        $this->assertSame(['products', 'customers', 'orders', 'carts', 'consent_build', 'ticimax_history'], $this->steps($ran));
        $this->assertSame(1, $this->runsOf('categories'));
        $this->assertSame(1, $this->store($this->igs)->callsOf('SelectKategori'));
        $this->assertSame(3, $this->inTenant($this->igs, fn () => DB::table('products')->count()));
        $this->assertSame(array_fill_keys(TicimaxOnboarding::STEPS, true), array_map(fn ($s) => $s['done'], $this->state()));
    }

    public function test_a_failed_connection_test_stops_before_any_sync(): void
    {
        $this->store($this->igs)->validKey = 'another-code';
        $this->startOnboarding();
        $this->drainQueue();

        Queue::assertNotPushed(SyncCommerceEntity::class);
        $state = $this->state();
        $this->assertSame(['failed', false], [$state['connection']['state'], $state['connection']['done']]);
        $this->asAdmin()->get($this->page())->assertSee('b-bad">Müdahale gerekli', false);
        // A rejected code blocks every sync button until it is fixed.
        $this->startOnboarding()->assertSessionHasErrors('ticimax_sync');
        $this->startStep('categories')->assertSessionHasErrors('ticimax_sync');
    }

    public function test_single_steps_keep_the_order_and_are_not_queued_twice(): void
    {
        $this->asAdmin()->from($this->page())->post("{$this->page()}/test")->assertSessionHas('status', 'Bağlantı testi başarılı.');

        $this->startStep('products')->assertSessionHasErrors(['ticimax_sync' => 'Önce "Kategoriler" adımı başarıyla tamamlanmalı.']);
        $this->startStep('categories')->assertSessionHasNoErrors()->assertSessionHas('status');
        Queue::assertPushed(SyncCommerceEntity::class, fn ($j) => $j->entity->value === 'categories' && ! $j->onboarding && $j->mode->value === 'full');
        $this->asAdmin()->get($this->page())->assertSee('Sırada');
        $this->startStep('categories')->assertSessionHasErrors(['ticimax_sync' => 'Kategoriler zaten sırada veya çalışıyor.']);
        Queue::assertPushed(SyncCommerceEntity::class, 1);

        $this->drainQueue();
        $this->assertSame('succeeded', $this->state()['categories']['state']);
        // A single step does not start the next one.
        Queue::assertPushed(SyncCommerceEntity::class, 1);
        $this->startStep('products')->assertSessionHasNoErrors();
        $this->asAdmin()->post("{$this->page()}/sync/everything")->assertNotFound();

        $audit = DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'integration.sync_started')->orderBy('created_at')->get(['actor_type', 'actor_id', 'metadata']);
        $this->assertSame(['platform_admin', $this->admin->id], [$audit[0]->actor_type, $audit[0]->actor_id]);
        $this->assertEquals(['provider' => 'ticimax', 'step' => 'categories', 'mode' => 'full', 'onboarding' => false, 'resume' => false], json_decode($audit[0]->metadata, true));
    }

    public function test_the_panel_shows_progress_retries_and_a_safe_error(): void
    {
        $this->inTenant($this->igs, fn () => DB::table('integration_sync_runs')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->igs->id, 'integration_id' => $this->integration->id, 'entity' => 'products', 'mode' => 'full',
            'status' => 'running', 'page_token' => 'all:3', 'records_read' => 200, 'records_inserted' => 100, 'records_skipped' => 100, 'errors' => 2, 'api_calls' => 2,
            'error_category' => 'retryable', 'error_message' => 'Temporary connection problem (ConnectionException); retrying.', 'started_at' => now(),
            'window_end' => now(),
        ]));

        $page = $this->asAdmin()->get($this->page())->assertOk();
        $page->assertSee('Yeniden deneniyor')->assertSee('all:3')->assertSee('Yeniden denenecek: Temporary connection problem (ConnectionException); retrying.')
            ->assertSee('<td>200</td><td>100</td><td>0</td><td>100</td>', false)->assertSee('sayfa 15 saniyede bir yenilenir');
        $this->assertSame('retrying', $this->state()['products']['state']);
        $this->startStep('products')->assertSessionHasErrors('ticimax_sync');
    }

    public function test_one_tenant_never_sees_or_starts_another_tenants_sync(): void
    {
        [$other] = $this->createTenant('baska-firma');
        $theirs = $this->connectTicimax($other, new FakeTicimax('other-code-1234'));
        $this->inTenant($other, fn () => DB::table('integration_sync_runs')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $other->id, 'integration_id' => $theirs->id, 'entity' => 'categories', 'mode' => 'full',
            'status' => 'failed', 'records_read' => 777, 'error_message' => 'OTHER-TENANT-ERROR', 'started_at' => now(), 'finished_at' => now(), 'window_end' => now(),
        ]));

        $this->asAdmin()->get($this->page())->assertOk()->assertDontSee('OTHER-TENANT-ERROR')->assertDontSee('777');
        $this->startOnboarding();
        $this->drainQueue();

        $this->assertSame([], $this->store($other)->calls, 'Their store is never called.');
        $this->assertSame(0, $this->inTenant($other, fn () => DB::table('categories')->count()));
        $this->assertSame(1, $this->inTenant($other, fn () => DB::table('integration_sync_runs')->count()));
        $this->assertNull(json_decode((string) $this->inTenant($other, fn () => DB::table('tenant_integrations')->value('settings')), true)['onboarding'] ?? null);
        $this->asAdmin()->post("{$this->host}/tenants/00000000-0000-7000-8000-000000000000/integrations/ticimax/onboarding")->assertNotFound();
        $this->asAdmin()->post("{$this->host}/tenants/00000000-0000-7000-8000-000000000000/integrations/ticimax/sync/categories")->assertNotFound();

        // RLS: no tenant context, no rows; the platform role reads counters
        // only, never error texts or page tokens.
        $this->assertSame(0, DB::table('integration_sync_runs')->count());
        $this->assertSame(0, DB::table('tenant_integrations')->count());
        $this->expectException(QueryException::class);
        DB::connection('pgsql_platform')->table('integration_sync_runs')->value('error_message');
    }

    public function test_sync_actions_need_a_recent_password_and_the_second_factor(): void
    {
        $owner = $this->addMember($this->igs, TenantRole::Owner);

        $this->startOnboarding(recent: false)->assertRedirect(route('admin.confirm'));
        $this->flushSession();
        $this->startOnboarding(twoFactor: false)->assertRedirect(route('admin.two-factor.challenge'));
        $this->flushSession();
        $this->asAdmin(recent: false)->post("{$this->page()}/sync/categories")->assertRedirect(route('admin.confirm'));
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post("{$this->page()}/onboarding")->assertRedirect(route('admin.login'));
        $this->actingAs($owner, 'web')->post("{$this->page()}/sync/categories")->assertRedirect(route('admin.login'));

        Queue::assertNothingPushed();
    }

    /**
     * Nowhere but the vault: not in the audit trail, the logs, the
     * integration errors, the runs or the session.
     */
    private function assertNowhere(string $secret): void
    {
        $everything = DB::connection('pgsql_platform')->table('audit_logs')->get(['action', 'metadata'])->toJson().implode("\n", $this->logs).json_encode(session()->all())
            .$this->inTenant($this->igs, fn () => DB::table('integration_errors')->get()->toJson().DB::table('integration_sync_runs')->get()->toJson().DB::table('tenant_integrations')->get(['settings', 'last_error'])->toJson());
        $this->assertStringNotContainsString($secret, $everything);
    }
}
