<?php

namespace Tests\Feature\Consent;

use App\Audit\Actor;
use App\Consent\ConsentChannel;
use App\Consent\ConsentSourceProvider;
use App\Consent\ConsentStatus;
use App\Consent\ConsentStore;
use App\Consent\ConsentStrategy;
use App\Consent\ConsentStrategyMode;
use App\Consent\EligibilityReason;
use App\Consent\RecipientType;
use App\Consent\SendEligibility;
use App\Consent\SuppressionService;
use App\Consent\SuppressionSource;
use App\Consent\Sync\ConsentSynchronizer;
use App\Email\EmailLaunchReadiness;
use App\Email\Unsubscribes;
use App\Email\UnsubscribeTokens;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Operations\ExternalBlockers;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithAutomation;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * merchant_verified (IGS, 2026-11-08): the tenant attested that its Ticimax
 * e-mail permissions are valid commercial message consents. Every negative
 * record still wins over a store permission, and the store sync must be
 * fresh.
 */
final class MerchantVerifiedConsentTest extends TestCase
{
    use InteractsWithAutomation, InteractsWithTenants;

    private Tenant $tenant;

    private TenantIntegration $commerce;

    private string $customer;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->tenant] = $this->createTenant('igs');
        $this->commerce = $this->commerce($this->tenant);
        $this->customer = $this->shopper($this->tenant, 'ayse@example.com', null);
    }

    private function commerce(Tenant $tenant, ?CarbonImmutable $syncedAt = null): TenantIntegration
    {
        $integration = $this->createIntegration($tenant, 'member-code-123', 'ticimax', 'commerce');
        $this->inTenant($tenant, function () use ($tenant, $integration, $syncedAt) {
            DB::table('tenant_integrations')->where('id', $integration->id)->update(['status' => 'connected']);
            DB::table('integration_sync_cursors')->insert(['tenant_id' => $tenant->id, 'integration_id' => $integration->id, 'entity' => ConsentSynchronizer::ENTITY_TICIMAX_HISTORY,
                'last_success_at' => $syncedAt ?? now(), 'last_window_end' => $syncedAt ?? now(), 'created_at' => now(), 'updated_at' => now()]);
        });

        return $integration;
    }

    private function merchantVerified(?Tenant $tenant = null): void
    {
        $this->inTenant($tenant ?? $this->tenant, fn () => app(ConsentStrategy::class)->set(ConsentStrategyMode::MerchantVerified,
            'Ticimax izinlerinin İYS karşılığı IGS tarafından doğrulandı', Actor::console('test'), 'IGS hukuk arşivi / izin-ispat-2026-11', 'Bilal Şimşek'));
    }

    private function store(string $email, ConsentStatus $status, string $asOf, ConsentSourceProvider $provider = ConsentSourceProvider::Ticimax, bool $explicit = false, ?Tenant $tenant = null): void
    {
        $this->inTenant($tenant ?? $this->tenant, fn () => app(ConsentStore::class)->observe(
            $this->recipient($email, $tenant), ConsentChannel::Email, RecipientType::Individual, $provider, $status, CarbonImmutable::parse($asOf), 'test:'.Str::random(10),
            consentDate: $status === ConsentStatus::Granted ? CarbonImmutable::parse($asOf) : null, metadata: $explicit ? ['explicit_change' => true] : []));
    }

    private function recipient(string $email = 'ayse@example.com', ?Tenant $tenant = null): string
    {
        return $this->inTenant($tenant ?? $this->tenant, fn () => (string) DB::table('consent_recipients')->where('address', $email)->value('id'));
    }

    private function reason(?string $customer = null, ?Tenant $tenant = null): EligibilityReason
    {
        return $this->inTenant($tenant ?? $this->tenant, fn () => app(SendEligibility::class)->forCustomer($customer ?? $this->customer)->reason);
    }

    private function suppress(SuppressionSource $source): void
    {
        $this->inTenant($this->tenant, fn () => app(SuppressionService::class)->suppress($this->recipient(), $source, ConsentChannel::Email, 'test', Actor::system()));
    }

    private function state(): array
    {
        return $this->inTenant($this->tenant, fn () => app(ConsentStrategy::class)->state());
    }

    public function test_it_is_never_the_default_and_a_store_permission_alone_does_not_allow_other_strategies(): void
    {
        $this->store('ayse@example.com', ConsentStatus::Granted, '2025-01-10');

        $this->assertSame('direct_api', $this->state()['strategy']);
        $this->assertNull($this->state()['merchant']);
        $this->assertSame(EligibilityReason::IysUnavailable, $this->reason());

        $this->inTenant($this->tenant, fn () => app(ConsentStrategy::class)->set(ConsentStrategyMode::ViaReconciliation, 'test', Actor::console('test')));
        $this->assertSame(EligibilityReason::IysUnavailable, $this->reason(), 'Ticimax MailIzin never stands in for İYS ONAY in the other strategies.');
    }

    public function test_a_granted_store_permission_allows_and_the_basis_is_labelled_as_store_consent_not_iys(): void
    {
        $this->store('ayse@example.com', ConsentStatus::Granted, '2025-01-10');
        $this->merchantVerified();

        $state = $this->state();
        $this->assertSame(['merchant_verified', true, null], [$state['strategy'], $state['ready'], $state['blocker']]);
        $this->assertSame('Mağaza izni (operasyonel doğrulama)', $state['source_label']);
        $this->assertStringContainsString('İYS doğrulaması değil', $state['label']);
        $this->assertFalse($state['direct_api']['required']);
        $this->assertSame(EligibilityReason::Allowed, $this->reason());

        // The newsletter list counts too.
        $other = $this->shopper($this->tenant, 'mehmet@example.com', null);
        $this->assertSame(EligibilityReason::MerchantNotGranted, $this->reason($other));
        $this->store('mehmet@example.com', ConsentStatus::Granted, '2025-02-01', ConsentSourceProvider::TicimaxNewsletter);
        $this->assertSame(EligibilityReason::Allowed, $this->reason($other));
    }

    public function test_every_negative_record_wins_over_a_store_permission(): void
    {
        $this->store('ayse@example.com', ConsentStatus::Granted, '2025-01-10');
        $this->merchantVerified();
        $this->assertSame(EligibilityReason::Allowed, $this->reason());

        foreach ([SuppressionSource::SesBounce, SuppressionSource::SesComplaint, SuppressionSource::Unsubscribe] as $source) {
            $this->suppress($source);
            $this->assertSame(EligibilityReason::LocalSuppressed, $this->reason(), $source->value.' must block.');
            $this->inTenant($this->tenant, fn () => DB::table('suppressions')->update(['lifted_at' => now()]));
            $this->assertSame(EligibilityReason::Allowed, $this->reason());
        }

        // An official RET (İYS / ViA / report) wins.
        $this->iys($this->tenant, 'ayse@example.com', ConsentStatus::Revoked);
        $this->assertSame(EligibilityReason::IysRevoked, $this->reason());
    }

    public function test_an_unsubscribe_link_blocks_at_once(): void
    {
        $this->store('ayse@example.com', ConsentStatus::Granted, '2025-01-10');
        $this->merchantVerified();
        config(['email.unsubscribe.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'email.unsubscribe.base_url' => 'https://go.pratiktools.test']);

        $token = app(UnsubscribeTokens::class)->issue($this->tenant->id, $this->recipient());
        $this->assertSame('unsubscribed', app(Unsubscribes::class)->apply($token, 'one_click'));
        $this->assertSame(EligibilityReason::LocalSuppressed, $this->reason());

        // Even with the suppression lifted, the opt-out after the permission still blocks.
        $this->inTenant($this->tenant, fn () => DB::table('suppressions')->update(['lifted_at' => now()]));
        $this->assertSame(EligibilityReason::MerchantNotGranted, $this->reason());
    }

    public function test_an_explicit_store_opt_out_wins_over_an_older_permission_and_ret_wins_a_tie(): void
    {
        $this->store('ayse@example.com', ConsentStatus::Granted, '2025-01-10', ConsentSourceProvider::TicimaxNewsletter);
        $this->merchantVerified();
        $this->assertSame(EligibilityReason::Allowed, $this->reason());

        $this->store('ayse@example.com', ConsentStatus::Revoked, '2025-03-01', explicit: true);
        $this->assertSame(EligibilityReason::MerchantNotGranted, $this->reason());

        // A newer store permission (the shopper opted in again) allows again.
        $this->store('ayse@example.com', ConsentStatus::Granted, '2025-04-01');
        $this->assertSame(EligibilityReason::Allowed, $this->reason());

        // Same instant, opposite answers: RET wins.
        $tie = $this->shopper($this->tenant, 'zeynep@example.com', null);
        $this->store('zeynep@example.com', ConsentStatus::Granted, '2025-05-01', ConsentSourceProvider::TicimaxNewsletter);
        $this->store('zeynep@example.com', ConsentStatus::Revoked, '2025-05-01', explicit: true);
        $this->assertSame(EligibilityReason::MerchantNotGranted, $this->reason($tie));
    }

    public function test_a_lead_form_checkbox_alone_is_never_a_permission(): void
    {
        $this->store('ayse@example.com', ConsentStatus::Granted, '2025-01-10', ConsentSourceProvider::LeadForm);
        $this->merchantVerified();

        $this->assertSame(EligibilityReason::MerchantNotGranted, $this->reason());
    }

    public function test_an_address_shared_by_several_customers_is_a_conflict(): void
    {
        $this->store('ayse@example.com', ConsentStatus::Granted, '2025-01-10');
        $this->merchantVerified();
        $second = $this->inTenant($this->tenant, function () {
            $id = (string) Str::uuid7();
            DB::table('customers')->insert(['id' => $id, 'tenant_id' => $this->tenant->id, 'email' => 'ayse@example.com', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('recipient_customers')->insert(['tenant_id' => $this->tenant->id, 'recipient_id' => $this->recipient(), 'customer_id' => $id, 'link_source' => 'test', 'created_at' => now()]);

            return $id;
        });

        $this->assertSame(EligibilityReason::IdentityConflict, $this->reason($second));
    }

    public function test_a_stale_or_missing_store_sync_blocks_everyone(): void
    {
        $this->store('ayse@example.com', ConsentStatus::Granted, '2025-01-10');
        $this->merchantVerified();
        $this->assertSame(EligibilityReason::Allowed, $this->reason());

        config(['consent.merchant.max_age_hours' => 24]);
        $this->inTenant($this->tenant, fn () => DB::table('integration_sync_cursors')->update(['last_success_at' => now()->subHours(25)]));
        $this->assertSame(['MERCHANT_CONSENT_STALE', false], [$this->state()['blocker'], $this->state()['ready']]);
        $this->assertSame(EligibilityReason::MerchantConsentStale, $this->reason());

        $this->inTenant($this->tenant, fn () => DB::table('integration_sync_cursors')->update(['last_success_at' => null]));
        $this->assertSame('MERCHANT_CONSENT_SYNC_MISSING', $this->state()['blocker']);
        $this->assertSame(EligibilityReason::MerchantConsentStale, $this->reason());

        $this->inTenant($this->tenant, fn () => DB::table('integration_sync_cursors')->update(['last_success_at' => now()]));
        $this->inTenant($this->tenant, fn () => DB::table('tenant_integrations')->where('id', $this->commerce->id)->update(['status' => 'error']));
        $this->assertSame('MERCHANT_COMMERCE_NOT_CONNECTED', $this->state()['blocker']);
        $this->assertSame(EligibilityReason::MerchantConsentStale, $this->reason());

        $this->inTenant($this->tenant, fn () => DB::table('tenant_integrations')->where('id', $this->commerce->id)->update(['status' => 'connected']));
        config(['consent.merchant.max_age_hours' => null]);
        $this->assertSame('MERCHANT_CONSENT_POLICY_MISSING', $this->state()['blocker']);
        $this->assertSame(EligibilityReason::MerchantConsentStale, $this->reason());
    }

    public function test_choosing_it_needs_evidence_and_operator_and_is_audited_and_cleared_on_change(): void
    {
        $this->artisan('consent:strategy', ['--tenant' => 'igs', '--set' => 'merchant_verified', '--reason' => 'Ticimax izinleri doğrulandı'])->assertFailed();
        $this->artisan('consent:strategy', ['--tenant' => 'igs', '--set' => 'merchant_verified', '--reason' => 'Ticimax izinleri doğrulandı', '--evidence-ref' => 'arşiv-1'])->assertFailed();
        $this->assertSame('direct_api', $this->state()['strategy']);

        $this->artisan('consent:strategy', ['--tenant' => 'igs', '--set' => 'merchant_verified', '--reason' => 'Ticimax izinleri doğrulandı',
            '--evidence-ref' => 'IGS hukuk arşivi / izin-ispat-2026-11', '--operator' => 'Bilal Şimşek'])->assertSuccessful();

        $attestation = $this->state()['merchant']['attestation'];
        $this->assertSame(['Ticimax izinleri doğrulandı', 'IGS hukuk arşivi / izin-ispat-2026-11', 'Bilal Şimşek', 'console:consent:strategy'],
            [$attestation['reason'], $attestation['evidence_ref'], $attestation['operator'], $attestation['actor']]);
        $this->assertNotEmpty($attestation['attested_at']);
        $audit = $this->inTenant($this->tenant, fn () => DB::table('audit_logs')->where('action', 'consent.strategy_changed')->latest('created_at')->first());
        $meta = (array) json_decode((string) $audit->metadata, true);
        $this->assertSame(['direct_api', 'merchant_verified', 'IGS hukuk arşivi / izin-ispat-2026-11', 'Bilal Şimşek'], [$meta['from'], $meta['to'], $meta['evidence_ref'], $meta['operator']]);

        $this->inTenant($this->tenant, fn () => app(ConsentStrategy::class)->set(ConsentStrategyMode::DirectApi, 'geri', Actor::console('test')));
        $this->assertNull($this->inTenant($this->tenant, fn () => DB::table('tenant_settings')->value('consent_strategy_attestation')));

        // The database refuses merchant_verified without an attestation.
        $this->expectException(QueryException::class);
        $this->inTenant($this->tenant, fn () => DB::table('tenant_settings')->update(['consent_strategy' => 'merchant_verified']));
    }

    public function test_launch_readiness_counts_sendable_recipients_with_a_reason_breakdown_and_new_consent_channels_only_warn(): void
    {
        // ayse: store permission. Others: store permission plus one negative record each, or none.
        $this->store('ayse@example.com', ConsentStatus::Granted, '2025-01-10');
        $people = ['unsub@example.com' => SuppressionSource::Unsubscribe, 'bounce@example.com' => SuppressionSource::SesBounce,
            'complaint@example.com' => SuppressionSource::SesComplaint, 'none@example.com' => null];
        foreach ($people as $email => $source) {
            $this->shopper($this->tenant, $email, null);
            if ($source !== null) {
                $this->store($email, ConsentStatus::Granted, '2025-01-10');
                $this->inTenant($this->tenant, fn () => app(SuppressionService::class)->suppress($this->recipient($email), $source, ConsentChannel::Email, 'test', Actor::system()));
            }
        }
        $launch = fn () => $this->inTenant($this->tenant, fn () => app(EmailLaunchReadiness::class)->evaluate(null));

        // Default strategy: nobody may be sent to.
        $this->assertSame(['BLOCKED', 0], [$launch()['checks']['AUDIENCE']['status'], $launch()['counts']['allowed']]);

        $this->merchantVerified();
        $l = $launch();
        $this->assertSame(['PASS', 'delivery'], [$l['checks']['CONSENT_BASIS']['status'], $l['checks']['CONSENT_BASIS']['group']]);
        $this->assertStringContainsString('İYS doğrulaması değil', $l['checks']['CONSENT_BASIS']['detail']);
        $this->assertStringContainsString('Bilal Şimşek', $l['checks']['CONSENT_BASIS']['detail']);
        $this->assertSame(['PASS', '1 / 5'], [$l['checks']['AUDIENCE']['status'], substr($l['checks']['AUDIENCE']['detail'], 0, 5)]);
        $this->assertSame(['recipients' => 5, 'allowed' => 1], array_intersect_key($l['counts'], ['recipients' => 1, 'allowed' => 1]));
        $this->assertSame(['ALLOWED' => 1, 'UNSUBSCRIBE' => 1, 'HARD_BOUNCE' => 1, 'COMPLAINT' => 1, 'OTHER_SUPPRESSION' => 0, 'MERCHANT_NOT_GRANTED' => 1,
            'MERCHANT_CONSENT_STALE' => 0, 'IDENTITY_CONFLICT' => 0], $l['breakdown']);

        // No İYS API, no ViA: shown as warnings of the acquisition group, never blocking.
        $this->assertSame(['WARN', 'acquisition'], [$l['checks']['VIA_FRAME']['status'], $l['checks']['VIA_FRAME']['group']]);
        $this->assertSame(['WARN', 'acquisition'], [$l['checks']['IYS_API_ACCESS']['status'], $l['checks']['IYS_API_ACCESS']['group']]);
        $this->assertNotContains('BLOCKED', array_column(array_filter($l['checks'], fn ($c) => $c['group'] === 'acquisition'), 'status'));
        // Still NO-GO: live dispatch is not built (Faz 3) and SES is not set up here.
        $this->assertSame(['NO-GO', 'BLOCKED'], [$l['verdict'], $l['checks']['LIVE_DISPATCH']['status']]);

        // A stale store sync takes everyone out at once.
        $this->inTenant($this->tenant, fn () => DB::table('integration_sync_cursors')->update(['last_success_at' => now()->subDays(3)]));
        $l = $launch();
        $this->assertSame(['BLOCKED', 'BLOCKED', 0], [$l['checks']['CONSENT_BASIS']['status'], $l['checks']['AUDIENCE']['status'], $l['counts']['allowed']]);
        // Suppressions still come first; everyone else is stale.
        $this->assertSame([2, 1, 1, 1], [$l['breakdown']['MERCHANT_CONSENT_STALE'], $l['breakdown']['UNSUBSCRIBE'], $l['breakdown']['HARD_BOUNCE'], $l['breakdown']['COMPLAINT']]);
    }

    public function test_external_blockers_show_the_store_consent_basis_and_only_delivery_rows_block(): void
    {
        $this->store('ayse@example.com', ConsentStatus::Granted, '2025-01-10');
        $rows = fn () => collect($this->inTenant($this->tenant, fn () => app(ExternalBlockers::class)->all()))->keyBy('blocker');

        // Default strategy: missing İYS API access is a delivery blocker, as before.
        $this->assertSame(['BLOCKED', 'delivery'], [$rows()['IYS_API_ACCESS']['status'], $rows()['IYS_API_ACCESS']['group']]);
        $this->assertFalse($rows()->has('MERCHANT_CONSENT_BASIS'));

        $this->merchantVerified();
        $r = $rows();
        $this->assertSame(['PASS', 'delivery'], [$r['MERCHANT_CONSENT_BASIS']['status'], $r['MERCHANT_CONSENT_BASIS']['group']]);
        $this->assertStringContainsString('İYS doğrulaması değil', $r['MERCHANT_CONSENT_BASIS']['detail']);
        $this->assertSame(['WARN', 'acquisition'], [$r['IYS_API_ACCESS']['status'], $r['IYS_API_ACCESS']['group']]);
        $this->assertSame(['WARN', 'acquisition'], [$r['IYS_VIA']['status'], $r['IYS_VIA']['group']]);
        $blocking = array_column(ExternalBlockers::blocking($r->values()->all()), 'blocker');
        $this->assertNotContains('IYS_API_ACCESS', $blocking);
        $this->assertNotContains('IYS_VIA', $blocking);
        $this->assertNotContains('MERCHANT_CONSENT_BASIS', $blocking);

        // A stale store sync makes the consent basis itself a blocker.
        $this->inTenant($this->tenant, fn () => DB::table('integration_sync_cursors')->update(['last_success_at' => now()->subDays(3)]));
        $this->assertSame('BLOCKED', $rows()['MERCHANT_CONSENT_BASIS']['status']);
        $this->assertContains('MERCHANT_CONSENT_BASIS', array_column(ExternalBlockers::blocking($rows()->values()->all()), 'blocker'));
    }

    public function test_it_is_tenant_isolated(): void
    {
        [$other] = $this->createTenant('other');
        $this->commerce($other);
        $otherCustomer = $this->shopper($other, 'ayse@example.com', null);
        $this->store('ayse@example.com', ConsentStatus::Granted, '2025-01-10', tenant: $other);
        $this->merchantVerified($other);

        // The other tenant's strategy and store permission never reach this one.
        $this->assertSame(EligibilityReason::Allowed, $this->reason($otherCustomer, $other));
        $this->assertSame('direct_api', $this->state()['strategy']);
        $this->assertSame(EligibilityReason::IysUnavailable, $this->reason());

        $this->merchantVerified();
        $this->assertSame(EligibilityReason::MerchantNotGranted, $this->reason());
    }
}
