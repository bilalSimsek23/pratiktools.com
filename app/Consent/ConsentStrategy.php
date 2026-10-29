<?php

namespace App\Consent;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Consent\Sync\ConsentSynchronizer;
use App\Consent\Via\ViaConsentProvider;
use App\Consent\Via\ViaStatus;
use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The tenant's consent strategy and whether it can currently back a
 * marketing decision (fail-closed):
 *
 *  direct_api           a full İYS status sync has succeeded (F2);
 *                       per-row freshness: consent.eligibility.iys_max_age_hours.
 *  via_reconciliation   ViA is configured, a freshness policy is set
 *                       (IYS_RECONCILIATION_MAX_AGE, hours) and at least
 *                       one İYS change report has been applied; the
 *                       latest applied report period must be younger
 *                       than the policy, else every decision is
 *                       IYS_RECONCILIATION_STALE. No API access needed.
 *
 * Only an operator changes the strategy (consent:strategy); the default
 * stays direct_api.
 */
final class ConsentStrategy
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ViaConsentProvider $via,
        private readonly AuditLogger $audit,
    ) {}

    public function mode(): ConsentStrategyMode
    {
        $value = DB::table('tenant_settings')->where('tenant_id', $this->context->requireTenant())->value('consent_strategy');

        return ConsentStrategyMode::tryFrom((string) $value) ?? ConsentStrategyMode::DirectApi;
    }

    public function set(ConsentStrategyMode $mode, string $reason, Actor $actor): void
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Changing the consent strategy needs a reason.');
        }
        $tenantId = $this->context->requireTenant();
        DB::transaction(function () use ($tenantId, $mode, $reason, $actor) {
            $from = $this->mode();
            DB::statement(
                'INSERT INTO tenant_settings (tenant_id, consent_strategy, created_at, updated_at) VALUES (?, ?, now(), now())
                 ON CONFLICT (tenant_id) DO UPDATE SET consent_strategy = EXCLUDED.consent_strategy, updated_at = now()',
                [$tenantId, $mode->value],
            );
            $this->audit->record(AuditAction::ConsentStrategyChanged, 'tenant', $tenantId, ['from' => $from->value, 'to' => $mode->value, 'reason' => mb_substr($reason, 0, 255)], $actor);
        });
    }

    /**
     * İYS counts as available once a full status sync has succeeded for the
     * tenant's consent integration.
     */
    public function directApiAvailable(): bool
    {
        $integration = TenantIntegration::query()->where('provider', 'iys')->where('capability', 'consent')->first();

        return $integration !== null && DB::table('integration_sync_cursors')
            ->where('integration_id', $integration->id)
            ->where('entity', ConsentSynchronizer::ENTITY_IYS_STATUS)
            ->whereNotNull('last_success_at')
            ->exists();
    }

    public static function maxAgeHours(): ?int
    {
        $value = config('consent.reconciliation.max_age_hours');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Inputs of the eligibility query: [operational, stale, per-row freshness applies].
     *
     * @return array{0: bool, 1: bool, 2: bool}
     */
    public function eligibilityFlags(): array
    {
        $mode = $this->mode();
        if ($mode === ConsentStrategyMode::DirectApi) {
            return [$this->directApiAvailable(), false, true];
        }
        $c = $this->core($mode);

        return [$c['operational'], $c['stale'], false];
    }

    /**
     * Readiness facts of a strategy.
     *
     * @return array{mode: ConsentStrategyMode, direct: bool, via: ViaStatus, max_age: ?int, applied: ?object, age_hours: ?float, stale: bool, operational: bool, blocker: ?string}
     */
    private function core(ConsentStrategyMode $mode): array
    {
        if ($mode === ConsentStrategyMode::DirectApi) {
            $direct = $this->directApiAvailable();

            return ['mode' => $mode, 'direct' => $direct, 'via' => $this->via->status(), 'max_age' => self::maxAgeHours(), 'applied' => $this->lastApplied(), 'age_hours' => null,
                'stale' => false, 'operational' => $direct, 'blocker' => $direct ? null : 'IYS_UNAVAILABLE'];
        }

        $via = $this->via->status();
        $maxAge = self::maxAgeHours();
        $applied = $this->lastApplied();
        // A report covers its whole last day.
        $ageHours = $applied === null ? null : max(0, round(CarbonImmutable::parse((string) $applied->period_end, 'UTC')->addDay()->diffInMinutes(CarbonImmutable::now(), true) / 60, 1));
        $stale = $maxAge !== null && $ageHours !== null && $ageHours > $maxAge;
        $blocker = match (true) {
            ! $via->configured() => (string) $via->blocker(),
            $maxAge === null => 'IYS_RECONCILIATION_POLICY_MISSING',
            $applied === null => 'IYS_RECONCILIATION_MISSING',
            $stale => 'IYS_RECONCILIATION_STALE',
            default => null,
        };

        return ['mode' => $mode, 'direct' => false, 'via' => $via, 'max_age' => $maxAge, 'applied' => $applied, 'age_hours' => $ageHours, 'stale' => $stale,
            'operational' => $via->configured() && $maxAge !== null && $applied !== null, 'blocker' => $blocker];
    }

    private function lastApplied(): ?object
    {
        return DB::table('iys_reconciliation_batches')->where('tenant_id', $this->context->requireTenant())->where('status', 'applied')->orderByDesc('period_end')
            ->first(['id', 'period_end', 'applied_at', 'applied_counts']);
    }

    /**
     * Everything the panel, readiness and eligibility need; no secret, no
     * address.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $tenantId = $this->context->requireTenant();
        $c = $this->core($this->mode());
        $mode = $c['mode'];
        $applied = $c['applied'];
        $last = DB::table('iys_reconciliation_batches')->where('tenant_id', $tenantId)->orderByDesc('created_at')->first(['id', 'status', 'period_end', 'created_at', 'error']);

        return [
            'strategy' => $mode->value,
            'label' => $mode->label(),
            'operational' => $c['operational'],
            'stale' => $c['stale'],
            'ready' => $c['blocker'] === null,
            'blocker' => $c['blocker'],
            'source_label' => $mode === ConsentStrategyMode::DirectApi ? 'İYS API' : 'ViA + İYS mutabakatı',
            'direct_api' => ['available' => $mode === ConsentStrategyMode::DirectApi ? $c['direct'] : $this->directApiAvailable(), 'required' => $mode === ConsentStrategyMode::DirectApi],
            'via' => ['state' => $c['via']->state, 'configured' => $c['via']->configured(), 'detail' => $c['via']->detail, 'level' => $c['via']->level()],
            'reconciliation' => [
                'max_age_hours' => $c['max_age'],
                'format_configured' => is_string(config('consent.reconciliation.format')) && isset(config('consent.reconciliation.parsers')[config('consent.reconciliation.format')]),
                'last_batch' => $last === null ? null : ['id' => $last->id, 'status' => $last->status, 'period_end' => $last->period_end, 'created_at' => $last->created_at, 'error' => $last->error],
                'last_applied' => $applied === null ? null : ['id' => $applied->id, 'period_end' => $applied->period_end, 'applied_at' => $applied->applied_at,
                    'counts' => (array) json_decode((string) $applied->applied_counts, true)],
                'age_hours' => $c['age_hours'],
                'stale' => $c['stale'],
                'open_conflicts' => DB::table('consent_conflicts')->where('tenant_id', $tenantId)->where('status', 'open')
                    ->whereIn('kind', [ConsentConflicts::OFFICIAL_ONAY_AFTER_LOCAL_RET, ConsentConflicts::OFFICIAL_SOURCES_DISAGREE])->count(),
            ],
        ];
    }
}
