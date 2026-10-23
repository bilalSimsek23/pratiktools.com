<?php

namespace Tests\Feature\Consent;

use App\Audit\Actor;
use App\Consent\ConsentChannel;
use App\Consent\ConsentSourceProvider;
use App\Consent\ConsentStatus;
use App\Consent\ConsentStore;
use App\Consent\EligibilityReason;
use App\Consent\RecipientDirectory;
use App\Consent\RecipientType;
use App\Consent\SendEligibility;
use App\Consent\SuppressionService;
use App\Consent\SuppressionSource;
use App\Consent\Sync\ConsentSynchronizer;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class SendEligibilityTest extends TestCase
{
    use InteractsWithTenants;

    private Tenant $tenant;

    private TenantIntegration $iys;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->tenant] = $this->createTenant('shop');
        $this->iys = $this->createIntegration($this->tenant, (string) json_encode(['username' => 'u', 'password' => 'p']), 'iys', 'consent');
        $this->markIysSynced($this->tenant, $this->iys);
    }

    private function markIysSynced(Tenant $tenant, TenantIntegration $iys): void
    {
        $this->inTenant($tenant, fn () => DB::table('integration_sync_cursors')->insert([
            'tenant_id' => $tenant->id, 'integration_id' => $iys->id, 'entity' => ConsentSynchronizer::ENTITY_IYS_STATUS,
            'last_success_at' => now(), 'last_window_end' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $consent
     */
    private function customer(string $email, array $consent = ['email_opt_in' => true], ?string $phone = null, ?bool $active = true): string
    {
        $id = (string) Str::uuid7();
        $this->inTenant($this->tenant, fn () => DB::table('customers')->insert([
            'id' => $id, 'tenant_id' => $this->tenant->id, 'email' => $email, 'phone' => $phone, 'is_active' => $active,
            'source_consent' => json_encode($consent + ['permission_updated_at' => '2025-01-01T00:00:00+00:00']), 'attributes' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]));

        return $id;
    }

    private function build(): void
    {
        $this->inTenant($this->tenant, fn () => app(ConsentSynchronizer::class)->buildFromCustomers());
    }

    private function recipient(string $email): string
    {
        return $this->inTenant($this->tenant, fn () => (string) DB::table('consent_recipients')->where('address', $email)->value('id'));
    }

    private function iys(string $email, ConsentStatus $status, RecipientType $type = RecipientType::Individual, ?CarbonImmutable $consentDate = null, ?CarbonImmutable $asOf = null): void
    {
        $this->inTenant($this->tenant, fn () => app(ConsentStore::class)->observe(
            $this->recipient($email), ConsentChannel::Email, $type, ConsentSourceProvider::Iys, $status, $asOf ?? CarbonImmutable::now(),
            eventKey: 'test:'.Str::random(8), consentDate: $consentDate ?? CarbonImmutable::parse('2025-01-10'),
        ));
    }

    private function reason(string $customerId): EligibilityReason
    {
        return $this->inTenant($this->tenant, fn () => app(SendEligibility::class)->forCustomer($customerId)->reason);
    }

    public function test_ticimax_true_and_iys_onay_is_allowed(): void
    {
        $c = $this->customer('ayse@example.com');
        $this->build();
        $this->iys('ayse@example.com', ConsentStatus::Granted);

        $this->assertSame(EligibilityReason::Allowed, $this->reason($c));
    }

    public function test_iys_ret_beats_ticimax_true(): void
    {
        $c = $this->customer('ayse@example.com', ['email_opt_in' => true]);
        $this->build();
        $this->iys('ayse@example.com', ConsentStatus::Revoked);

        $this->assertSame(EligibilityReason::IysRevoked, $this->reason($c));
    }

    public function test_ret_beats_onay_across_recipient_types(): void
    {
        $c = $this->customer('ayse@example.com');
        $this->build();
        $this->iys('ayse@example.com', ConsentStatus::Granted);
        $this->iys('ayse@example.com', ConsentStatus::Revoked, RecipientType::Merchant);

        $this->assertSame(EligibilityReason::IysRevoked, $this->reason($c));
    }

    public function test_ticimax_false_without_an_explicit_opt_out_follows_iys_onay(): void
    {
        $c = $this->customer('ayse@example.com', ['email_opt_in' => false]);
        $this->build();
        $this->iys('ayse@example.com', ConsentStatus::Granted);

        // Policy: a never-ticked store flag is no opt-out; İYS is authoritative.
        $this->assertSame(EligibilityReason::Allowed, $this->reason($c));
    }

    public function test_explicit_store_opt_out_after_the_iys_consent_blocks(): void
    {
        $c = $this->customer('ayse@example.com', ['email_opt_in' => true]);
        $this->build();
        $this->iys('ayse@example.com', ConsentStatus::Granted, consentDate: CarbonImmutable::parse('2025-01-10'));
        $this->inTenant($this->tenant, fn () => app(ConsentStore::class)->observe(
            $this->recipient('ayse@example.com'), ConsentChannel::Email, RecipientType::Individual, ConsentSourceProvider::Ticimax, ConsentStatus::Revoked,
            CarbonImmutable::parse('2026-03-01'), eventKey: 'history:1', metadata: ['explicit_change' => true],
        ));

        $this->assertSame(EligibilityReason::SourceRevoked, $this->reason($c));

        config(['consent.eligibility.store_opt_out_blocks' => false]);
        $this->assertSame(EligibilityReason::Allowed, $this->reason($c));
    }

    public function test_unknown_pending_stale_and_unavailable_block(): void
    {
        $c = $this->customer('ayse@example.com');
        $this->build();
        $this->assertSame(EligibilityReason::IysUnknown, $this->reason($c), 'Never queried.');

        $this->iys('ayse@example.com', ConsentStatus::Unknown);
        $this->assertSame(EligibilityReason::IysUnknown, $this->reason($c));

        $this->iys('ayse@example.com', ConsentStatus::Pending);
        $this->assertSame(EligibilityReason::IysPending, $this->reason($c));

        $this->iys('ayse@example.com', ConsentStatus::Granted);
        $this->inTenant($this->tenant, fn () => DB::table('consents')->where('source_provider', 'IYS')->update(['last_verified_at' => now()->subDays(3)]));
        $this->assertSame(EligibilityReason::IysStale, $this->reason($c));

        $this->inTenant($this->tenant, fn () => DB::table('integration_sync_cursors')->update(['last_success_at' => null]));
        $this->assertSame(EligibilityReason::IysUnavailable, $this->reason($c));
    }

    public function test_merchant_only_onay_needs_a_policy_decision(): void
    {
        $c = $this->customer('muhasebe@example.com');
        $this->build();
        $this->iys('muhasebe@example.com', ConsentStatus::Granted, RecipientType::Merchant);

        $this->assertSame(EligibilityReason::MerchantPolicyReview, $this->reason($c));
    }

    public function test_local_suppression_blocks_and_lifting_is_explicit_and_audited(): void
    {
        $c = $this->customer('ayse@example.com');
        $this->build();
        $this->iys('ayse@example.com', ConsentStatus::Granted);
        $service = app(SuppressionService::class);

        $id = $this->inTenant($this->tenant, fn () => $service->suppress($this->recipient('ayse@example.com'), SuppressionSource::Manual, null, 'complaint', Actor::system()));
        $this->assertSame(EligibilityReason::LocalSuppressed, $this->reason($c));

        $this->inTenant($this->tenant, function () use ($service, $id) {
            try {
                $service->lift($id, ' ', Actor::system());
                $this->fail('A lift without reason must be refused.');
            } catch (\InvalidArgumentException) {
            }
            $service->lift($id, 'customer asked again', Actor::system());
        });

        $this->assertSame(EligibilityReason::Allowed, $this->reason($c));
        $this->inTenant($this->tenant, function () {
            $this->assertSame(1, DB::table('suppressions')->whereNotNull('lifted_at')->count(), 'Lifted, not deleted.');
            $this->assertSame(['consent.suppression_created', 'consent.suppression_lifted'], DB::table('audit_logs')->where('action', 'like', 'consent.%')->orderBy('created_at')->orderBy('id')->pluck('action')->all());
        });
    }

    public function test_invalid_and_marketplace_addresses_are_blocked(): void
    {
        $invalid = $this->customer('not-an-email');
        $relay = $this->customer('x123@trendyolmail.com');
        $none = $this->customer('');
        $this->build();

        $this->assertSame(EligibilityReason::InvalidEmail, $this->reason($invalid));
        $this->assertSame(EligibilityReason::MarketplaceIdentity, $this->reason($relay));
        $this->assertSame(EligibilityReason::NoRecipient, $this->reason($none));
    }

    public function test_shared_email_keeps_customers_apart_and_blocks_as_identity_conflict(): void
    {
        $a = $this->customer('shared@example.com');
        $b = $this->customer('Shared@Example.com ');
        $this->build();
        $this->iys('shared@example.com', ConsentStatus::Granted);

        $this->inTenant($this->tenant, function () {
            $this->assertSame(2, DB::table('customers')->count(), 'No merge.');
            $this->assertSame(1, DB::table('consent_recipients')->where('address_type', 'email')->count(), 'One recipient.');
            $this->assertSame(2, DB::table('recipient_customers')->count());
        });
        $this->assertSame(EligibilityReason::IdentityConflict, $this->reason($a));
        $this->assertSame(EligibilityReason::IdentityConflict, $this->reason($b));
    }

    public function test_shared_phone_never_links_customers_or_moves_consent(): void
    {
        $a = $this->customer('a@example.com', ['email_opt_in' => true, 'sms_opt_in' => true], '0532 111 22 33');
        $b = $this->customer('b@example.com', ['email_opt_in' => true, 'sms_opt_in' => false], '+905321112233');
        $this->build();
        $this->iys('a@example.com', ConsentStatus::Granted);
        $this->iys('b@example.com', ConsentStatus::Revoked);

        $this->inTenant($this->tenant, function () {
            $this->assertSame(2, DB::table('customers')->count());
            $this->assertSame(1, DB::table('consent_recipients')->where('address_type', 'phone')->count());
            // Shared number: any "no" wins for the number's store SMS flag.
            $this->assertSame('REVOKED', DB::table('consents')->where('channel', 'SMS')->value('status'));
        });
        $this->assertSame(EligibilityReason::Allowed, $this->reason($a), 'B\'s RET must not move to A through the phone.');
        $this->assertSame(EligibilityReason::IysRevoked, $this->reason($b));
    }

    public function test_sms_and_push_are_not_evaluated_yet(): void
    {
        $c = $this->customer('a@example.com');
        $this->build();

        $this->assertSame(EligibilityReason::ChannelNotSupported, $this->inTenant($this->tenant, fn () => app(SendEligibility::class)->forCustomer($c, ConsentChannel::Sms)->reason));
        $this->assertSame(EligibilityReason::ChannelNotSupported, $this->inTenant($this->tenant, fn () => app(SendEligibility::class)->forCustomer($c, ConsentChannel::Push)->reason));
    }

    public function test_audience_counts_agree_with_single_decisions(): void
    {
        $ids = [
            $this->customer('a@example.com'), $this->customer('b@example.com'), $this->customer('c@example.com'),
            $this->customer('bad-address'), $this->customer('d@trendyolmail.com'),
        ];
        $this->build();
        $this->iys('a@example.com', ConsentStatus::Granted);
        $this->iys('b@example.com', ConsentStatus::Revoked);

        $single = array_count_values(array_map(fn ($id) => $this->reason($id)->value, $ids));
        $bulk = $this->inTenant($this->tenant, fn () => app(SendEligibility::class)->reasonCounts());
        ksort($single);
        ksort($bulk);

        $this->assertSame($single, $bulk);
        $this->assertSame(1, $bulk['ALLOWED']);
    }

    public function test_tenant_b_cannot_see_or_use_tenant_a_consent(): void
    {
        $c = $this->customer('ayse@example.com');
        $this->build();
        $this->iys('ayse@example.com', ConsentStatus::Granted);
        [$other] = $this->createTenant('other');

        $this->inTenant($other, function () use ($c) {
            $this->assertSame(0, DB::table('consents')->count());
            $this->assertSame(0, DB::table('consent_recipients')->count());
            $this->assertSame(0, DB::table('consent_events')->count());
            $this->assertSame(EligibilityReason::NoRecipient, app(SendEligibility::class)->forCustomer($c)->reason);
        });
    }

    public function test_consent_events_are_append_only_and_idempotent(): void
    {
        $this->customer('ayse@example.com');
        $this->build();
        $this->build();

        $this->inTenant($this->tenant, function () {
            $this->assertSame(1, DB::table('consent_events')->count(), 'Rebuilding the snapshot adds nothing.');
            $this->expectException(QueryException::class);
            DB::table('consent_events')->delete();
        });
    }

    public function test_recipient_directory_normalizes_without_guessing(): void
    {
        $dir = app(RecipientDirectory::class);

        $this->assertSame('ayse@example.com', $dir->email('  AYSE@Example.COM ')['address']);
        $this->assertFalse($dir->email('ayse@')['valid']);
        $this->assertSame('+905321112233', $dir->phone('0532 111 22 33')['address']);
        $this->assertNull($dir->phone('12'));
    }
}
