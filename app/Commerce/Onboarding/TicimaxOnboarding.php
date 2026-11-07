<?php

namespace App\Commerce\Onboarding;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Commerce\ConnectionTester;
use App\Commerce\Sync\SyncEntity;
use App\Commerce\Sync\SyncMode;
use App\Consent\Sync\ConsentSynchronizer;
use App\Jobs\AdvanceTicimaxOnboarding;
use App\Jobs\SyncCommerceEntity;
use App\Jobs\SyncConsent;
use App\Models\TenantIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The Ticimax data setup of one tenant, run from Super Admin instead of
 * console commands: connection test, then full syncs of categories,
 * products, customers, orders and carts, the consent recipient build
 * (consent:build --skip-history) and the Ticimax permission history.
 *
 * It adds no sync engine of its own. Each step is the existing queued job
 * (SyncCommerceEntity, SyncConsent) with its run tracking, cursor, slices,
 * idempotent writes and transient retry; this class only reads their runs
 * and cursors and starts them in order. A step counts as done once a full
 * run of it succeeded, so the onboarding never repeats finished steps and
 * stops at a failed one until it is started again.
 *
 * Everything runs inside the tenant's context (runtime role, RLS). Only
 * read operations reach Ticimax.
 */
final class TicimaxOnboarding
{
    public const STEPS = ['connection', 'categories', 'products', 'customers', 'orders', 'carts', 'consent_build', 'ticimax_history'];

    public const LABELS = [
        'connection' => 'Bağlantı testi', 'categories' => 'Kategoriler', 'products' => 'Ürünler', 'customers' => 'Müşteriler',
        'orders' => 'Siparişler', 'carts' => 'Sepetler', 'consent_build' => 'İzin alıcıları (consent build)', 'ticimax_history' => 'Ticimax izin geçmişi',
    ];

    public const OVERALL = [
        'not_configured' => 'Yapılandırılmadı', 'waiting_connection' => 'Bağlantı bekliyor', 'waiting_setup' => 'Kurulum bekliyor',
        'syncing' => 'Veri senkronize ediliyor', 'action_required' => 'Müdahale gerekli', 'ready' => 'Hazır',
    ];

    /** A request older than this without a run is shown as not started again. */
    private const QUEUED_HOURS = 6;

    public function __construct(
        private readonly ConnectionTester $tester,
        private readonly AuditLogger $audit,
    ) {}

    public static function entity(string $step): string
    {
        return match ($step) {
            'consent_build' => ConsentSynchronizer::ENTITY_CONSENT_BUILD,
            'ticimax_history' => ConsentSynchronizer::ENTITY_TICIMAX_HISTORY,
            'connection' => throw new InvalidArgumentException('The connection test has no sync run.'),
            default => SyncEntity::from($step)->value,
        };
    }

    /**
     * State of every step, from the integration, its runs and cursors.
     *
     * @return array<string, array{label: string, state: string, done: bool, busy: bool, resumable: bool, last_success_at: ?string, queued_at: ?string, run: ?array<string, mixed>}>
     */
    public function steps(TenantIntegration $integration): array
    {
        $requests = (array) ($integration->settings['sync_requests'] ?? []);
        $entities = array_map(fn ($s) => self::entity($s), array_slice(self::STEPS, 1));
        $cursors = DB::table('integration_sync_cursors')->where('integration_id', $integration->id)->whereIn('entity', $entities)->get()->keyBy('entity');
        // The latest run of each step's entity.
        $ranked = DB::table('integration_sync_runs')->where('integration_id', $integration->id)->whereIn('entity', $entities)
            ->select(['entity', 'mode', 'status', 'records_read', 'records_inserted', 'records_updated', 'records_skipped', 'errors', 'api_calls',
                'slices', 'page_token', 'window_start', 'window_end', 'started_at', 'finished_at', 'duration_ms', 'error_category', 'error_message'])
            ->selectRaw('row_number() OVER (PARTITION BY entity ORDER BY started_at DESC) AS n');
        $latest = DB::query()->fromSub($ranked, 'r')->where('n', 1)->get()->keyBy('entity');

        $steps = ['connection' => $this->connectionStep($integration, $requests['connection'] ?? null)];
        foreach (array_slice(self::STEPS, 1) as $step) {
            $entity = self::entity($step);
            $cursor = $cursors[$entity] ?? null;
            $run = $latest[$entity] ?? null;
            $queuedAt = $requests[$step] ?? null;
            $queued = $queuedAt !== null && CarbonImmutable::parse($queuedAt)->gt(now()->subHours(self::QUEUED_HOURS))
                && ($run === null || CarbonImmutable::parse($queuedAt)->gt(CarbonImmutable::parse($run->started_at)));

            $state = match (true) {
                $run?->status === 'running' => $run->error_category !== null ? 'retrying' : 'running',
                $queued => 'queued',
                $run === null => 'not_started',
                default => $run->status,
            };
            $done = $cursor !== null && $cursor->last_success_at !== null && ($step === 'ticimax_history' || $cursor->last_full_sync_at !== null);

            $steps[$step] = [
                'label' => self::LABELS[$step], 'state' => $state, 'done' => $done, 'busy' => in_array($state, ['queued', 'running', 'retrying'], true),
                'resumable' => $run?->status === 'failed' && $run->page_token !== null && $run->finished_at !== null
                    && CarbonImmutable::parse($run->finished_at)->gt(now()->subHours((int) config('commerce.sync.resume_hours'))),
                'last_success_at' => $cursor?->last_success_at, 'queued_at' => $queuedAt, 'run' => $run === null ? null : (array) $run,
            ];
        }

        return $steps;
    }

    /**
     * @param  array<string, array{state: string, done: bool, busy: bool}>  $steps
     */
    public function overall(TenantIntegration $integration, array $steps): string
    {
        $failed = array_filter($steps, fn ($s) => $s['state'] === 'failed');

        return match (true) {
            $integration->encrypted_secret === null => 'not_configured',
            $integration->status === 'action_required' || $failed !== [] => 'action_required',
            ! $steps['connection']['done'] => 'waiting_connection',
            array_filter($steps, fn ($s) => ! $s['done']) === [] => 'ready',
            array_filter($steps, fn ($s) => $s['busy']) !== [] || $this->active($integration) => 'syncing',
            default => 'waiting_setup',
        };
    }

    /**
     * The first step that is not done yet, or null when all are.
     *
     * @param  ?array<string, array{done: bool}>  $steps  steps() when already read
     */
    public function next(TenantIntegration $integration, ?array $steps = null): ?string
    {
        foreach ($steps ?? $this->steps($integration) as $step => $s) {
            if (! $s['done']) {
                return $step;
            }
        }

        return null;
    }

    public function active(TenantIntegration $integration): bool
    {
        return (bool) ($integration->settings['onboarding']['active'] ?? false);
    }

    /**
     * "Kurulumu Başlat" and "Başarısız adımdan devam et": the job runs the
     * first unfinished step; each successful step queues the next one.
     */
    public function start(TenantIntegration $integration, Actor $actor): void
    {
        $this->assertReady($integration);
        $this->setSetting($integration, 'onboarding', ['active' => true, 'started_at' => now()->toIso8601String(), 'by' => $actor->type]);
        $this->audit->record(AuditAction::IntegrationSyncStarted, 'tenant_integration', $integration->id, ['provider' => 'ticimax', 'onboarding' => true, 'from_step' => $this->next($integration)], $actor);
        AdvanceTicimaxOnboarding::dispatch($integration->id, $integration->tenant_id);
    }

    /**
     * Queues one step's full run. Steps go in order: every earlier step must
     * be done; a step that is already queued or running is not queued again.
     */
    public function startStep(TenantIntegration $integration, string $step, Actor $actor, bool $onboarding = false): void
    {
        if (! in_array($step, self::STEPS, true) || $step === 'connection') {
            throw new InvalidArgumentException('Bilinmeyen adım.');
        }
        $this->assertReady($integration);
        $steps = $this->steps($integration);
        if ($steps[$step]['busy']) {
            throw new InvalidArgumentException(self::LABELS[$step].' zaten sırada veya çalışıyor.');
        }
        foreach (array_slice(self::STEPS, 0, (int) array_search($step, self::STEPS, true)) as $before) {
            if (! $steps[$before]['done']) {
                throw new InvalidArgumentException('Önce "'.self::LABELS[$before].'" adımı başarıyla tamamlanmalı.');
            }
        }

        $this->setSetting($integration, 'sync_requests', [$step => now()->toIso8601String()], merge: true);
        $this->audit->record(AuditAction::IntegrationSyncStarted, 'tenant_integration', $integration->id,
            ['provider' => 'ticimax', 'step' => $step, 'mode' => 'full', 'onboarding' => $onboarding, 'resume' => $steps[$step]['resumable']], $actor);

        match ($step) {
            'consent_build', 'ticimax_history' => SyncConsent::dispatch($integration->id, $step, $integration->tenant_id, $onboarding),
            default => SyncCommerceEntity::dispatch($integration->id, SyncEntity::from($step), SyncMode::Full, $integration->tenant_id, null, $onboarding),
        };
    }

    /**
     * One onboarding move (AdvanceTicimaxOnboarding): queue the first
     * unfinished step, or finish. The connection test runs right here; the
     * other steps hand back when their run succeeded. A failed step is not
     * retried by itself: the chain waits for "devam et".
     */
    public function advance(TenantIntegration $integration): void
    {
        if (! $this->active($integration)) {
            return;
        }

        $steps = $this->steps($integration);
        $step = $this->next($integration, $steps);
        if ($step === null) {
            $this->setSetting($integration, 'onboarding', ['active' => false, 'completed_at' => now()->toIso8601String()] + (array) ($integration->settings['onboarding'] ?? []));
            $this->audit->record(AuditAction::IntegrationSyncCompleted, 'tenant_integration', $integration->id, ['provider' => 'ticimax', 'onboarding' => true], Actor::job(AdvanceTicimaxOnboarding::class));

            return;
        }
        if ($steps[$step]['busy']) {
            return;
        }

        if ($step === 'connection') {
            if ($this->tester->test($integration, Actor::job(AdvanceTicimaxOnboarding::class))->ok) {
                $this->advance($integration->refresh());
            }

            return;
        }

        $this->startStep($integration, $step, Actor::job(AdvanceTicimaxOnboarding::class), onboarding: true);
    }

    /**
     * @return array{label: string, state: string, done: bool, busy: bool, resumable: bool, last_success_at: ?string, queued_at: ?string, run: null}
     */
    private function connectionStep(TenantIntegration $integration, ?string $queuedAt): array
    {
        $done = $integration->last_verified_at !== null && ! in_array($integration->status, ['pending', 'action_required'], true);
        $state = match (true) {
            $done => 'succeeded',
            $integration->status === 'action_required' || ($integration->status === 'error' && $integration->last_verified_at === null) => 'failed',
            default => 'not_started',
        };

        return ['label' => self::LABELS['connection'], 'state' => $state, 'done' => $done, 'busy' => false, 'resumable' => false,
            'last_success_at' => $integration->last_verified_at?->toDateTimeString(), 'queued_at' => $queuedAt, 'run' => null];
    }

    private function assertReady(TenantIntegration $integration): void
    {
        if ($integration->encrypted_secret === null) {
            throw new InvalidArgumentException('Önce Ticimax yetki kodunu kaydedin.');
        }
        if ($integration->status === 'action_required') {
            throw new InvalidArgumentException('Ticimax yetki kodunu reddetti; kodu güncelleyip bağlantıyı test edin.');
        }
    }

    /**
     * Writes one top-level key of the settings in place, so a concurrent
     * save of the URLs (or another key) is never overwritten.
     *
     * @param  array<string, mixed>  $value
     */
    private function setSetting(TenantIntegration $integration, string $key, array $value, bool $merge = false): void
    {
        DB::update(
            $merge
                ? 'UPDATE tenant_integrations SET settings = jsonb_set(settings, ARRAY[?], COALESCE(settings->?, \'{}\'::jsonb) || ?::jsonb, true), updated_at = now() WHERE id = ?'
                : 'UPDATE tenant_integrations SET settings = jsonb_set(settings, ARRAY[?], ?::jsonb, true), updated_at = now() WHERE id = ?',
            $merge ? [$key, $key, json_encode($value, JSON_THROW_ON_ERROR), $integration->id] : [$key, json_encode($value, JSON_THROW_ON_ERROR), $integration->id],
        );
        $integration->refresh();
    }
}
