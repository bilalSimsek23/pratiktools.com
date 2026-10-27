<?php

namespace Tests\Feature\Consent;

use App\Audit\Actor;
use App\Automation\ActionEligibility;
use App\Campaigns\CampaignAudience;
use App\Campaigns\CampaignReadiness;
use App\Campaigns\Campaigns;
use App\Campaigns\CampaignStatus;
use App\Consent\ConsentChannel;
use App\Consent\ConsentStrategy;
use App\Consent\ConsentStrategyMode;
use App\Consent\EligibilityReason;
use App\Consent\Reconciliation\IysReconciliationImporter;
use App\Consent\Reconciliation\ReconciliationError;
use App\Consent\Reconciliation\ReconciliationFile;
use App\Consent\SendEligibility;
use App\Consent\SuppressionService;
use App\Consent\SuppressionSource;
use App\Consent\Via\ViaConsentEvent;
use App\Consent\Via\ViaConsentHandler;
use App\Consent\Via\ViaConsentProvider;
use App\Consent\Via\ViaError;
use App\Email\Data\EmailDraft;
use App\Email\EmailDelivery;
use App\Email\EmailMessageType;
use App\Email\Templates\EmailTemplates;
use App\Email\Unsubscribes;
use App\Email\UnsubscribeTokens;
use App\Http\Middleware\PanelRecentAuth;
use App\Http\Middleware\PanelTenant;
use App\Jobs\SendEmail;
use App\Leads\LeadForms;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Operations\ExternalBlockers;
use App\Segments\Segments;
use App\Tenancy\Exceptions\TenantMismatch;
use App\Tenancy\TenantRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithAutomation;
use Tests\Concerns\InteractsWithSes;
use Tests\Concerns\InteractsWithTenants;
use Tests\Support\FakeViaProvider;
use Tests\Support\TestChangeReportParser;
use Tests\TestCase;

/**
 * F2-R: ViA consent + İYS daily reconciliation.
 */
final class ViaReconciliationTest extends TestCase
{
    use InteractsWithAutomation, InteractsWithSes, InteractsWithTenants;

    private const HEADER = "address;channel;recipient_type;status;occurred_at;source\n";

    private const ORIGIN = 'https://www.igs.example';

    private Tenant $tenant;

    private User $owner;

    private TenantIntegration $ses;

    private string $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSes();
        Queue::fake([SendEmail::class]);
        [$this->tenant, $this->owner] = $this->createTenant('shop');
        $this->ses = $this->connectSes($this->tenant);
        $this->customer = $this->shopper($this->tenant, 'ayse@example.com', null);
    }

    private function viaReady(?int $maxAge = 48): void
    {
        $this->app->bind(ViaConsentProvider::class, FakeViaProvider::class);
        config(['consent.reconciliation.max_age_hours' => $maxAge, 'consent.reconciliation.format' => 'test',
            'consent.reconciliation.parsers' => ['test' => TestChangeReportParser::class]]);
        $this->strategy(ConsentStrategyMode::ViaReconciliation);
    }

    private function strategy(ConsentStrategyMode $mode, ?Tenant $tenant = null): void
    {
        $this->inTenant($tenant ?? $this->tenant, fn () => app(ConsentStrategy::class)->set($mode, 'test', Actor::console('test')));
    }

    private function file(string $content, string $name = 'iys-degisiklik.csv'): ReconciliationFile
    {
        $path = tempnam(sys_get_temp_dir(), 'iys');
        file_put_contents($path, $content);

        return ReconciliationFile::open($path, $name);
    }

    /**
     * @return array{batch_id: string, status: string, counts: array<string, int>, error: ?string}
     */
    private function analyze(string $rows, ?string $periodEnd = null, ?Tenant $tenant = null): array
    {
        return $this->inTenant($tenant ?? $this->tenant, fn () => app(IysReconciliationImporter::class)->analyze($this->file(self::HEADER.$rows),
            CarbonImmutable::parse($periodEnd ?? CarbonImmutable::yesterday()->toDateString()), null, Actor::console('test')));
    }

    /**
     * @return array<string, int>
     */
    private function apply(string $batchId, ?Tenant $tenant = null): array
    {
        return $this->inTenant($tenant ?? $this->tenant, fn () => app(IysReconciliationImporter::class)->apply($batchId, Actor::console('test')));
    }

    private function reconcile(string $rows, ?string $periodEnd = null): array
    {
        return $this->apply($this->analyze($rows, $periodEnd)['batch_id']);
    }

    private function reason(?string $customer = null): EligibilityReason
    {
        return $this->inTenant($this->tenant, fn () => app(SendEligibility::class)->forCustomer($customer ?? $this->customer)->reason);
    }

    private function recipient(string $email = 'ayse@example.com'): string
    {
        return $this->inTenant($this->tenant, fn () => (string) DB::table('consent_recipients')->where('address', $email)->value('id'));
    }

    private function rows(string $table, ?callable $where = null): int
    {
        return $this->inTenant($this->tenant, fn () => ($where === null ? DB::table($table) : $where(DB::table($table)))->count());
    }

    private function via(string $kind, string $reference, bool $success = true, string $txn = 'txn-1', string $frame = FakeViaProvider::FRAME): string
    {
        return $this->inTenant($this->tenant, fn () => app(ViaConsentHandler::class)->handle(new ViaConsentEvent($kind, $success, $frame, $reference, $txn,
            CarbonImmutable::now()->subMinute(), failureCode: $success ? null : 'user_cancelled')));
    }

    /**
     * @return array{0: string, 1: string} form id, public id
     */
    private function viaForm(): array
    {
        return $this->inTenant($this->tenant, function () {
            $forms = app(LeadForms::class);
            $id = $forms->create('Footer', 'footer', 'Kampanyalardan e-posta ile haberdar olmak istiyorum.', 'Teşekkürler.', [self::ORIGIN], Actor::system());
            $forms->setConsentMode($id, 'via', Actor::system());
            $forms->setActive($id, true, Actor::system());

            return [$id, (string) DB::table('lead_forms')->where('id', $id)->value('public_id')];
        });
    }

    private function subscribe(string $publicId, string $email): void
    {
        $this->call('POST', "/public/v1/forms/{$publicId}/subscribe", [], [], [], ['HTTP_ORIGIN' => self::ORIGIN, 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.9'],
            (string) json_encode(['email' => $email, 'consent' => true]))->assertStatus(202);
    }

    public function test_default_strategy_is_unchanged_and_via_stays_fail_closed_until_every_part_is_configured(): void
    {
        $state = fn () => $this->inTenant($this->tenant, fn () => app(ConsentStrategy::class)->state());
        $this->assertSame('direct_api', $state()['strategy'], 'Nothing switches the strategy automatically.');
        $this->assertSame(EligibilityReason::IysUnavailable, $this->reason());

        // The operator chooses ViA, but no ViA configuration exists (production today).
        $this->strategy(ConsentStrategyMode::ViaReconciliation);
        $this->assertSame('VIA_NOT_CONFIGURED', $state()['blocker']);
        $this->assertSame('not_configured', $state()['via']['state']);
        $this->assertSame(EligibilityReason::IysUnavailable, $this->reason());
        try {
            $this->via(ViaConsentEvent::CONSENT, 'via_x');
            $this->fail('An unconfigured ViA must refuse events.');
        } catch (ViaError $e) {
            $this->assertSame('VIA_NOT_CONFIGURED', $e->reason);
        }

        // ViA configured, but no freshness policy: still fail-closed.
        $this->viaReady(maxAge: null);
        $this->assertSame('IYS_RECONCILIATION_POLICY_MISSING', $state()['blocker']);
        config(['consent.reconciliation.max_age_hours' => 48]);
        $this->assertSame('IYS_RECONCILIATION_MISSING', $state()['blocker']);
        $this->assertSame(EligibilityReason::IysUnavailable, $this->reason());
        $this->assertFalse($state()['direct_api']['required']);
    }

    public function test_the_audited_operator_command_changes_the_strategy(): void
    {
        $this->artisan('consent:strategy', ['--tenant' => 'shop', '--set' => 'via_reconciliation'])->assertFailed();
        $this->artisan('consent:strategy', ['--tenant' => 'shop', '--set' => 'via_reconciliation', '--reason' => 'İYS API paketi yok'])->assertSuccessful();

        $this->assertSame('via_reconciliation', $this->inTenant($this->tenant, fn () => DB::table('tenant_settings')->value('consent_strategy')));
        $this->assertSame(1, $this->rows('audit_logs', fn ($q) => $q->where('action', 'consent.strategy_changed')));
    }

    public function test_reconciliation_onay_allows_and_a_later_ret_blocks_at_once(): void
    {
        $this->viaReady();
        $counts = $this->reconcile('ayse@example.com;EMAIL;INDIVIDUAL;ONAY;2026-10-20T10:00:00+03:00;HS_WEB'."\n");
        $this->assertSame(1, $counts['onay']);
        $this->assertSame(EligibilityReason::Allowed, $this->reason());

        $this->reconcile('ayse@example.com;EMAIL;INDIVIDUAL;RET;2026-10-25T09:00:00+03:00;VATANDAS'."\n");
        $this->assertSame(EligibilityReason::IysRevoked, $this->reason());
        $event = $this->inTenant($this->tenant, fn () => DB::table('consent_events')->where('source_provider', 'IYS_RECONCILIATION')->where('new_status', 'REVOKED')->sole());
        $this->assertNotNull($event->reconciliation_batch_id);
        $this->assertSame('GRANTED', $event->old_status, 'The superseded state is kept.');
    }

    public function test_dry_run_changes_nothing_and_apply_changes_once(): void
    {
        $this->viaReady();
        $before = $this->rows('consents');
        $result = $this->analyze('ayse@example.com;EMAIL;INDIVIDUAL;ONAY;2026-10-20T10:00:00Z;HS_WEB'."\n");

        $this->assertSame(['analyzed', 1], [$result['status'], $result['counts']['onay']]);
        $this->assertSame($before, $this->rows('consents'));
        $this->assertSame(0, $this->rows('consent_events', fn ($q) => $q->where('source_provider', 'IYS_RECONCILIATION')));
        $this->assertSame('IYS_RECONCILIATION_MISSING', $this->inTenant($this->tenant, fn () => app(ConsentStrategy::class)->state()['blocker']), 'A dry-run refreshes nothing.');

        $this->apply($result['batch_id']);
        $this->assertSame(EligibilityReason::Allowed, $this->reason());
        try {
            $this->apply($result['batch_id']);
            $this->fail('A batch is applied once.');
        } catch (ReconciliationError $e) {
            $this->assertSame('ALREADY_APPLIED', $e->reason);
        }
    }

    public function test_the_same_report_or_the_same_change_never_applies_twice(): void
    {
        $this->viaReady();
        $rows = 'ayse@example.com;EMAIL;INDIVIDUAL;ONAY;2026-10-20T10:00:00Z;HS_WEB'."\n";
        $this->reconcile($rows);
        $events = $this->rows('consent_events');

        try {
            $this->analyze($rows);
            $this->fail('The same file cannot be imported again.');
        } catch (ReconciliationError $e) {
            $this->assertSame('ALREADY_APPLIED', $e->reason);
        }
        // Same change inside another report (different file): duplicate.
        $counts = $this->reconcile($rows.'yok@example.com;EMAIL;INDIVIDUAL;RET;2026-10-21T10:00:00Z;X'."\n", CarbonImmutable::today()->toDateString());
        $this->assertSame([1, 1], [$counts['duplicate'], $counts['unknown_recipient']]);
        $this->assertSame($events, $this->rows('consent_events'));
        $this->assertSame(2, $this->rows('iys_reconciliation_batches'));
    }

    public function test_rows_are_classified_and_no_address_is_stored(): void
    {
        $this->viaReady();
        $this->shopper($this->tenant, 'mehmet@example.com', null);
        $result = $this->analyze(implode("\n", [
            'ayse@example.com;EMAIL;INDIVIDUAL;ONAY;2026-10-20T10:00:00Z;HS_WEB',
            'ayse@example.com;EMAIL;INDIVIDUAL;ONAY;2026-10-20T10:00:00Z;HS_WEB',
            'mehmet@example.com;EMAIL;INDIVIDUAL;RET;2026-10-20T11:00:00Z;VATANDAS',
            'bilinmeyen@example.com;EMAIL;INDIVIDUAL;ONAY;2026-10-20T11:00:00Z;HS_WEB',
            'xyz123@trendyolmail.com;EMAIL;INDIVIDUAL;ONAY;2026-10-20T11:00:00Z;HS_WEB',
            'not-an-address;EMAIL;INDIVIDUAL;ONAY;2026-10-20T11:00:00Z;HS_WEB',
            'ayse@example.com;EMAIL;INDIVIDUAL;BELKI;2026-10-20T11:00:00Z;HS_WEB',
        ])."\n");

        $c = $result['counts'];
        $this->assertSame([7, 1, 1, 1, 1, 1, 2], [$c['rows'], $c['onay'], $c['ret'], $c['duplicate'], $c['unknown_recipient'], $c['marketplace_excluded'], $c['invalid']]);
        $stored = (string) json_encode($this->inTenant($this->tenant, fn () => DB::table('iys_consent_changes')->get()));
        foreach (['ayse@', 'mehmet@', 'bilinmeyen@', 'trendyolmail', 'not-an-address'] as $pii) {
            $this->assertStringNotContainsString($pii, $stored);
        }
        $this->assertSame(0, $this->rows('consent_recipients', fn ($q) => $q->where('address', 'bilinmeyen@example.com')), 'No recipient is created from a report.');
    }

    public function test_conflicts_are_recorded_never_silently_overwritten(): void
    {
        $this->viaReady();
        $this->reconcile('ayse@example.com;EMAIL;INDIVIDUAL;RET;2026-10-25T10:00:00Z;VATANDAS'."\n", '2026-10-25');

        // An older ONAY arriving later does not undo the newer RET.
        $counts = $this->reconcile('ayse@example.com;EMAIL;INDIVIDUAL;ONAY;2026-10-01T10:00:00Z;HS_WEB'."\n", CarbonImmutable::yesterday()->toDateString());
        $this->assertSame(1, $counts['conflict']);
        $this->assertSame(EligibilityReason::IysRevoked, $this->reason());
        $this->assertSame(1, $this->rows('consent_conflicts', fn ($q) => $q->where('kind', 'OFFICIAL_SOURCES_DISAGREE')->where('status', 'open')));

        // A newer official ONAY for an address the recipient unsubscribed locally: the local block stays.
        $this->inTenant($this->tenant, fn () => app(SuppressionService::class)->suppress($this->recipient(), SuppressionSource::Unsubscribe, ConsentChannel::Email, 'test', Actor::system()));
        $counts = $this->reconcile('ayse@example.com;EMAIL;INDIVIDUAL;ONAY;'.CarbonImmutable::now()->subHour()->toIso8601String().";HS_WEB\n", CarbonImmutable::today()->toDateString());
        $this->assertSame(1, $counts['conflict']);
        $this->assertSame(EligibilityReason::LocalSuppressed, $this->reason());
        $this->assertSame(1, $this->rows('suppressions', fn ($q) => $q->whereNull('lifted_at')));
        $this->assertSame(1, $this->rows('consent_conflicts', fn ($q) => $q->where('kind', 'OFFICIAL_ONAY_AFTER_LOCAL_RET')));
    }

    public function test_ret_wins_a_tie_and_stale_reconciliation_blocks_everyone(): void
    {
        $this->viaReady();
        $at = '2026-10-20T10:00:00Z';
        $this->reconcile("ayse@example.com;EMAIL;INDIVIDUAL;RET;{$at};A\n", '2026-10-20');
        $counts = $this->reconcile("ayse@example.com;EMAIL;INDIVIDUAL;ONAY;{$at};B\n", CarbonImmutable::yesterday()->toDateString());
        $this->assertSame(1, $counts['conflict']);
        $this->assertSame(EligibilityReason::IysRevoked, $this->reason());

        $mehmet = $this->shopper($this->tenant, 'mehmet@example.com', null);
        $this->reconcile("mehmet@example.com;EMAIL;INDIVIDUAL;ONAY;{$at};B\n", CarbonImmutable::today()->toDateString());
        $this->assertSame(EligibilityReason::Allowed, $this->reason($mehmet));

        $this->travel(4)->days();
        $this->assertSame(EligibilityReason::IysReconciliationStale, $this->reason($mehmet));
        $state = $this->inTenant($this->tenant, fn () => app(ConsentStrategy::class)->state());
        $this->assertSame(['IYS_RECONCILIATION_STALE', true], [$state['blocker'], $state['reconciliation']['stale']]);
        $blockers = collect($this->inTenant($this->tenant, fn () => app(ExternalBlockers::class)->all()))->keyBy('blocker');
        $this->assertSame('BLOCKED', $blockers['IYS_VIA_RECONCILIATION']['status']);
        $this->assertArrayNotHasKey('IYS_API_ACCESS', $blockers->all(), 'The İYS API is not required with ViA + reconciliation.');
        // RET still blocks immediately while stale.
        $this->assertSame(EligibilityReason::IysRevoked, $this->reason());
    }

    public function test_without_a_configured_format_uploads_are_refused_not_guessed(): void
    {
        $this->strategy(ConsentStrategyMode::ViaReconciliation);
        $result = $this->analyze('ayse@example.com;EMAIL;INDIVIDUAL;ONAY;2026-10-20T10:00:00Z;HS_WEB'."\n");

        $this->assertSame(['unsupported', 'FORMAT_NOT_CONFIGURED', []], [$result['status'], $result['error'], $result['counts']]);
        $this->assertSame(0, $this->rows('iys_consent_changes'));
        $this->assertSame(EligibilityReason::IysUnavailable, $this->reason());
    }

    public function test_untrusted_files_are_guarded(): void
    {
        $this->viaReady();
        $long = str_repeat('a', 5000).'@example.com;EMAIL;INDIVIDUAL;ONAY;2026-10-20T10:00:00Z;X';
        $result = $this->analyze("ayse@example.com;EMAIL;INDIVIDUAL;ONAY;2026-10-20T10:00:00Z;=HYPERLINK(\"http://x\")\n\xff\xfe broken\n{$long}\nmehmet\0@example.com;EMAIL;INDIVIDUAL;ONAY;2026-10-20T10:00:00Z;X\n");

        $this->assertSame([1, 3], [$result['counts']['onay'], $result['counts']['invalid']]);
        $details = $this->inTenant($this->tenant, fn () => DB::table('iys_consent_changes')->where('outcome', 'invalid')->orderBy('row_number')->pluck('detail')->all());
        $this->assertSame(['ENCODING', 'LINE_TOO_LONG', 'ENCODING'], $details);
        $this->assertSame('HYPERLINK("http://x")', $this->inTenant($this->tenant, fn () => DB::table('iys_consent_changes')->where('outcome', 'onay')->value('source')));

        config(['consent.reconciliation.max_file_bytes' => 10]);
        foreach (['FILE_TOO_LARGE' => self::HEADER.'x', 'FILE_EMPTY' => ''] as $reason => $content) {
            try {
                $this->file($content);
                $this->fail("{$reason} expected");
            } catch (ReconciliationError $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
        $this->assertSame('_x_.._evil.csv', ReconciliationFile::open(tap(tempnam(sys_get_temp_dir(), 'iys'), fn ($p) => file_put_contents($p, 'x')), '=../"x".._evil.csv')->originalName);
    }

    public function test_via_lead_stays_pending_until_a_verified_success_and_success_is_idempotent(): void
    {
        $this->viaReady();
        $this->reconcile("ayse@example.com;EMAIL;INDIVIDUAL;ONAY;2026-10-20T10:00:00Z;HS_WEB\n");
        Http::preventStrayRequests();
        [, $publicId] = $this->viaForm();
        $this->subscribe($publicId, 'yeni@example.com');

        $customer = $this->inTenant($this->tenant, fn () => (string) DB::table('recipient_customers')->where('recipient_id', $this->recipient('yeni@example.com'))->value('customer_id'));
        $this->assertSame(EligibilityReason::IysPending, $this->reason($customer));
        $this->assertSame(0, $this->rows('consent_write_intents'), 'ViA writes İYS itself: no İYS intent.');
        $this->assertSame(0, $this->rows('customer_events', fn ($q) => $q->where('name', 'NEWSLETTER_SUBSCRIBED')), 'No newsletter event before ViA succeeds.');
        $reference = $this->inTenant($this->tenant, fn () => (string) DB::table('via_consent_requests')->value('reference'));

        $this->assertSame('applied', $this->via(ViaConsentEvent::CONSENT, $reference));
        $this->assertSame(EligibilityReason::Allowed, $this->reason($customer));
        $this->assertSame('duplicate', $this->via(ViaConsentEvent::CONSENT, $reference));
        $this->assertSame('duplicate', $this->via(ViaConsentEvent::CONSENT, $reference, txn: 'txn-2'));
        $this->assertSame('unknown_reference', $this->via(ViaConsentEvent::CONSENT, 'via_unknown', txn: 'txn-3'));
        $this->assertSame(1, $this->rows('customer_events', fn ($q) => $q->where('name', 'NEWSLETTER_SUBSCRIBED')));
        $this->assertSame(1, $this->rows('consents', fn ($q) => $q->where('source_provider', 'VIA')));
    }

    public function test_via_failure_never_grants_and_a_foreign_frame_is_refused(): void
    {
        $this->viaReady();
        $this->reconcile("ayse@example.com;EMAIL;INDIVIDUAL;ONAY;2026-10-20T10:00:00Z;HS_WEB\n");
        [, $publicId] = $this->viaForm();
        $this->subscribe($publicId, 'yeni@example.com');
        $this->subscribe($publicId, 'ikinci@example.com');
        $refs = $this->inTenant($this->tenant, fn () => DB::table('via_consent_requests')->orderBy('created_at')->pluck('reference')->all());

        $this->assertSame('failed', $this->via(ViaConsentEvent::CONSENT, $refs[0], success: false));
        $customer = $this->inTenant($this->tenant, fn () => (string) DB::table('recipient_customers')->where('recipient_id', $this->recipient('yeni@example.com'))->value('customer_id'));
        $this->assertSame(EligibilityReason::IysUnknown, $this->reason($customer));
        $this->assertSame('duplicate', $this->via(ViaConsentEvent::CONSENT, $refs[0], txn: 'txn-9'), 'A failed acquisition cannot be turned into ONAY later.');

        $this->expectException(TenantMismatch::class);
        $this->via(ViaConsentEvent::CONSENT, $refs[1], txn: 'txn-4', frame: 'frame-of-another-tenant');
    }

    public function test_unsubscribe_blocks_at_once_and_stays_blocked_whatever_via_ret_does(): void
    {
        $this->viaReady();
        $this->reconcile("ayse@example.com;EMAIL;INDIVIDUAL;ONAY;2026-10-20T10:00:00Z;HS_WEB\n");
        $this->assertSame(EligibilityReason::Allowed, $this->reason());

        $token = app(UnsubscribeTokens::class)->issue($this->tenant->id, $this->recipient());
        $this->assertSame('unsubscribed', app(Unsubscribes::class)->apply($token, 'one_click'));
        $this->assertSame(EligibilityReason::LocalSuppressed, $this->reason());
        $intent = $this->inTenant($this->tenant, fn () => DB::table('consent_write_intents')->sole());
        $this->assertSame(['RET', 'via_ret', 'pending_write_disabled'], [$intent->status, $intent->route, $intent->state]);
        $this->assertStringStartsWith('ret_', (string) $intent->reference);

        $this->assertSame('failed', $this->via(ViaConsentEvent::RET, (string) $intent->reference, success: false, txn: 'ret-1'));
        $this->assertSame(EligibilityReason::LocalSuppressed, $this->reason());

        // İYS confirms the RET through the daily report.
        $this->reconcile('ayse@example.com;EMAIL;INDIVIDUAL;RET;'.CarbonImmutable::now()->subMinutes(5)->toIso8601String().";HS_WEB\n", CarbonImmutable::today()->toDateString());
        $this->assertSame('confirmed', $this->inTenant($this->tenant, fn () => DB::table('consent_write_intents')->value('state')));
        $this->assertSame(1, $this->rows('suppressions', fn ($q) => $q->whereNull('lifted_at')));
    }

    public function test_via_ret_success_confirms_the_ret(): void
    {
        $this->viaReady();
        $token = app(UnsubscribeTokens::class)->issue($this->tenant->id, $this->recipient());
        app(Unsubscribes::class)->apply($token, 'link');
        $reference = $this->inTenant($this->tenant, fn () => (string) DB::table('consent_write_intents')->value('reference'));

        $this->assertSame('applied', $this->via(ViaConsentEvent::RET, $reference, txn: 'ret-ok'));
        $this->assertSame('duplicate', $this->via(ViaConsentEvent::RET, $reference, txn: 'ret-ok'));
        $this->assertSame('confirmed', $this->inTenant($this->tenant, fn () => DB::table('consent_write_intents')->value('state')));
        $this->assertSame(1, $this->rows('consents', fn ($q) => $q->where('source_provider', 'VIA')->where('status', 'REVOKED')));
    }

    public function test_reconciliation_is_tenant_isolated(): void
    {
        $this->viaReady();
        $batch = $this->analyze("ayse@example.com;EMAIL;INDIVIDUAL;ONAY;2026-10-20T10:00:00Z;HS_WEB\n")['batch_id'];
        [$other] = $this->createTenant('other');
        $this->shopper($other, 'ayse@example.com', null);

        $this->assertSame(0, $this->inTenant($other, fn () => DB::table('iys_reconciliation_batches')->count()));
        $this->assertSame(0, $this->inTenant($other, fn () => DB::table('iys_consent_changes')->count()));
        $this->assertSame(0, $this->inTenant($other, fn () => DB::table('via_consent_requests')->count()));
        try {
            $this->apply($batch, $other);
            $this->fail('Another tenant cannot apply this batch.');
        } catch (ReconciliationError $e) {
            $this->assertSame('NOT_FOUND', $e->reason);
        }
        // The same file is a separate batch for another tenant and never touches this one.
        $this->strategy(ConsentStrategyMode::ViaReconciliation, $other);
        $this->apply($this->analyze("ayse@example.com;EMAIL;INDIVIDUAL;RET;2026-10-20T10:00:00Z;HS_WEB\n", null, $other)['batch_id'], $other);
        $this->apply($batch);
        $this->assertSame(EligibilityReason::Allowed, $this->reason());
        // Without a tenant context nothing is visible (FORCE RLS).
        $this->assertSame(0, DB::table('iys_consent_changes')->count());
    }

    public function test_campaign_readiness_audience_automation_and_send_time_all_recheck_consent(): void
    {
        $this->viaReady();
        $this->stats($this->tenant, $this->customer, now()->subDays(3)->toImmutable());
        $this->reconcile("ayse@example.com;EMAIL;INDIVIDUAL;ONAY;2026-10-20T10:00:00Z;HS_WEB\n");
        $sender = $this->senderIdentity($this->tenant, $this->ses);
        $campaign = $this->inTenant($this->tenant, function () use ($sender) {
            $t = app(EmailTemplates::class);
            $template = $t->create('T', EmailMessageType::Marketing, Actor::system());
            $v = $t->addVersion($template, 'Kasım', '<p>Merhaba</p><a href="{{ unsubscribe_url }}">Çık</a>', 'Merhaba {{ unsubscribe_url }}', Actor::system());
            $t->publish($v, Actor::system());
            $segment = app(Segments::class)->create('Son 30 gün', 'dynamic', ['all' => [['field' => 'last_order_at', 'op' => 'within_last_days', 'value' => 30]]], Actor::system());
            $id = app(Campaigns::class)->create(['name' => 'Kasım', 'type' => 'newsletter', 'segment_id' => $segment, 'template_version_id' => $v,
                'integration_id' => $this->ses->id, 'sender_identity_id' => $sender], Actor::system());
            app(Campaigns::class)->transition($id, CampaignStatus::Ready, Actor::system());

            return $id;
        });
        $report = fn () => $this->inTenant($this->tenant, fn () => app(CampaignAudience::class)->report(app(Campaigns::class)->find($campaign)));
        $readiness = $this->inTenant($this->tenant, fn () => app(CampaignReadiness::class)->evaluate(app(Campaigns::class)->find($campaign)));
        $this->assertSame(['via_reconciliation', 'ViA + İYS mutabakatı'], [$readiness['consent']['strategy'], $readiness['consent']['source']]);
        $this->assertNotNull($readiness['consent']['last_reconciliation']);
        $this->assertSame('PASS', collect($readiness['checks'])->firstWhere('check', 'IYS')['status']);
        $this->assertSame(1, $report()['currently_eligible']);
        $this->assertTrue($this->inTenant($this->tenant, fn () => app(ActionEligibility::class)->email($this->customer, 'marketing')['ok']));

        // A marketing message is queued while ONAY, then İYS reports RET.
        // Even an allowlisted test recipient needs consent for marketing.
        $this->emailModes($this->tenant, 'test_only', 'internal');
        $this->emailTestRecipient($this->tenant, 'ayse@example.com');
        $message = $this->inTenant($this->tenant, fn () => app(EmailDelivery::class)->queue(new EmailDraft($this->ses->id, $sender, EmailMessageType::Marketing, 'ayse@example.com',
            'Kasım', 'Metin', '<p>HTML</p>', headers: ['List-Unsubscribe' => '<https://go.pratiktools.test/u/x>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']), Actor::system()));
        $this->reconcile('ayse@example.com;EMAIL;INDIVIDUAL;RET;'.CarbonImmutable::now()->subHour()->toIso8601String().";VATANDAS\n", CarbonImmutable::today()->toDateString());

        // The frozen audience did not freeze consent.
        $this->assertSame(0, $report()['currently_eligible']);
        $this->assertSame('IYS_REVOKED', $this->inTenant($this->tenant, fn () => app(ActionEligibility::class)->email($this->customer, 'marketing')['reason']));
        $this->assertSame('blocked', $this->inTenant($this->tenant, fn () => app(EmailDelivery::class)->send($message, Actor::system())));
        $this->assertSame('IYS_REVOKED', $this->inTenant($this->tenant, fn () => DB::table('email_messages')->where('id', $message)->value('block_reason')));
        $this->assertSame([], $this->sesRequestsTo('/v2/email/outbound-emails'), 'No provider call.');
    }

    public function test_panel_reconciliation_screen_dry_run_and_apply_with_permissions(): void
    {
        $this->viaReady();
        $as = fn (User $u) => $this->actingAs($u, 'web')->withSession([PanelTenant::SESSION_TENANT => $this->tenant->id, PanelRecentAuth::SESSION_KEY => time()]);
        $upload = fn () => ['report' => UploadedFile::fake()->createWithContent('rapor.csv', self::HEADER."ayse@example.com;EMAIL;INDIVIDUAL;ONAY;2026-10-20T10:00:00Z;HS_WEB\n"),
            'period_end' => CarbonImmutable::yesterday()->toDateString()];

        $as($this->owner)->get('/app/iys/reconciliation')->assertOk()->assertSee('ViA + Günlük mutabakat')->assertSee('Rapor yükle');
        $marketer = $this->addMember($this->tenant, TenantRole::Marketer);
        $as($marketer)->get('/app/iys/reconciliation')->assertOk()->assertDontSee('Yükle ve analiz et');
        $as($marketer)->post('/app/iys/reconciliation', $upload())->assertForbidden();

        $as($this->owner)->post('/app/iys/reconciliation', $upload())->assertRedirect();
        $batch = $this->inTenant($this->tenant, fn () => (string) DB::table('iys_reconciliation_batches')->value('id'));
        $as($this->owner)->get("/app/iys/reconciliation/{$batch}")->assertOk()->assertSee('a***@e***.com')->assertDontSee('ayse@example.com')->assertSee('Uygula');
        $this->assertSame(EligibilityReason::IysUnavailable, $this->reason(), 'Dry-run only.');

        $as($marketer)->post("/app/iys/reconciliation/{$batch}/apply")->assertForbidden();
        $as($this->owner)->post("/app/iys/reconciliation/{$batch}/apply")->assertRedirect();
        $this->assertSame(EligibilityReason::Allowed, $this->reason());
        $as($this->owner)->get('/app/integrations')->assertOk()->assertSee('ViA + günlük mutabakat');
        $as($this->owner)->get('/app/suppressions')->assertOk()->assertSee('İYS mutabakatı bekleniyor');
    }

    public function test_reconciliation_tables_are_hidden_from_the_platform_role(): void
    {
        $this->viaReady();
        $this->reconcile("ayse@example.com;EMAIL;INDIVIDUAL;ONAY;2026-10-20T10:00:00Z;HS_WEB\n");

        foreach (['iys_reconciliation_batches', 'iys_consent_changes', 'via_consent_requests'] as $table) {
            try {
                DB::connection('pgsql_platform')->table($table)->count();
                $this->fail("Platform role must not read {$table}.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('permission denied', $e->getMessage());
            }
        }
    }
}
