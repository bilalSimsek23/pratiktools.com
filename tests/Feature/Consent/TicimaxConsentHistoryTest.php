<?php

namespace Tests\Feature\Consent;

use App\Commerce\CommerceSourceFactory;
use App\Consent\Sync\ConsentSynchronizer;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithTicimax;
use Tests\Support\FakeTicimax;
use Tests\TestCase;

final class TicimaxConsentHistoryTest extends TestCase
{
    use InteractsWithTenants, InteractsWithTicimax;

    private Tenant $tenant;

    private TenantIntegration $commerce;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTicimax();
        config(['consent.ticimax.history_start' => '2026-07-01']);
        [$this->tenant] = $this->createTenant('shop');
        $this->commerce = $this->connectTicimax($this->tenant);
    }

    public function test_history_maps_channels_reads_monthly_windows_and_is_idempotent(): void
    {
        $shop = $this->store($this->tenant);
        $shop->consentHistory = [
            FakeTicimax::consentChange(1, 7, 2, true, 'Ayse@Example.com', '2026-07-05T10:00:00'),
            FakeTicimax::consentChange(2, 7, 2, false, 'ayse@example.com', '2026-09-10T10:00:00'),
            FakeTicimax::consentChange(3, 7, 1, true, 'ayse@example.com', '2026-09-10T10:00:00'),
        ];

        $run = $this->inTenant($this->tenant, fn () => app(ConsentSynchronizer::class)->ticimaxHistory($this->commerce->fresh()));
        $again = $this->inTenant($this->tenant, fn () => app(ConsentSynchronizer::class)->ticimaxHistory($this->commerce->fresh(), CarbonImmutable::parse('2026-07-01')));

        $this->assertSame('succeeded', $run->status, (string) $run->error_message);
        $this->assertGreaterThanOrEqual(4, $shop->callsOf('SelectUyeIzinGecmisi'), 'One call per month, no multi-year window.');
        $this->assertSame(0, $again->records_updated);
        $this->inTenant($this->tenant, function () {
            $email = DB::table('consents')->where('channel', 'EMAIL')->where('source_provider', 'TICIMAX')->first();
            $this->assertSame('REVOKED', $email->status, 'IzinTip 2 = e-mail; latest change wins.');
            $this->assertTrue(json_decode($email->metadata, true)['explicit_change']);
            $this->assertSame('GRANTED', DB::table('consents')->where('channel', 'SMS')->where('source_provider', 'TICIMAX')->value('status'), 'IzinTip 1 = SMS.');
            $this->assertSame(3, DB::table('consent_events')->where('source_provider', 'TICIMAX')->count());
            $this->assertNotNull(DB::table('integration_sync_cursors')->where('entity', 'ticimax_consent_history')->value('last_window_end'));
        });
    }

    public function test_an_older_store_change_never_overwrites_a_newer_state(): void
    {
        $shop = $this->store($this->tenant);
        $shop->consentHistory = [FakeTicimax::consentChange(2, 7, 2, false, 'ayse@example.com', '2026-09-10T10:00:00')];
        $this->inTenant($this->tenant, fn () => app(ConsentSynchronizer::class)->ticimaxHistory($this->commerce->fresh()));

        $shop->consentHistory = [FakeTicimax::consentChange(1, 7, 2, true, 'ayse@example.com', '2026-07-05T10:00:00')];
        $this->inTenant($this->tenant, fn () => app(ConsentSynchronizer::class)->ticimaxHistory($this->commerce->fresh(), CarbonImmutable::parse('2026-07-01')));

        $this->inTenant($this->tenant, function () {
            $this->assertSame('REVOKED', DB::table('consents')->where('source_provider', 'TICIMAX')->value('status'));
            $late = DB::table('consent_events')->where('event_key', 'history:1')->first();
            $this->assertTrue((bool) $late->is_backfill);
            $this->assertTrue(json_decode($late->metadata, true)['stale_observation']);
        });
    }

    public function test_consent_history_is_a_read_operation_and_writes_stay_blocked(): void
    {
        $client = $this->inTenant($this->tenant, fn () => app(CommerceSourceFactory::class)->for($this->commerce->fresh(), 'test'))->client();

        foreach (['UpdateUyeIzin', 'BulkUpdateUyeIzin'] as $op) {
            try {
                $client->call('UyeServis', $op);
                $this->fail("{$op} must be rejected.");
            } catch (\LogicException) {
            }
        }
        $this->assertSame(0, $this->store($this->tenant)->callsOf('UpdateUyeIzin'));
    }
}
