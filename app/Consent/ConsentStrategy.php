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
 *  merchant_verified    the tenant attested (reason, evidence reference,
 *                       operator, time) that its store (Ticimax) e-mail
 *                       permissions are valid commercial message consents.
 *                       Not an İYS or ViA verification. Operational while
 *                       the attestation exists, the store integration is
 *                       connected and its permission history sync succeeded
 *                       within consent.merchant.max_age_hours; otherwise
 *                       every decision is MERCHANT_CONSENT_STALE.
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

    /**
     * merchant_verified needs $evidenceRef (where the tenant keeps the proof
     * of its store consents) and $operator (who verified it); both are
     * stored with the reason and time and audited. Any other strategy
     * clears the attestation.
     */
    public function set(ConsentStrategyMode $mode, string $reason, Actor $actor, ?string $evidenceRef = null, ?string $operator = null): void
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Changing the consent strategy needs a reason.');
        }
        $merchant = $mode === ConsentStrategyMode::MerchantVerified;
        if ($merchant && (trim((string) $evidenceRef) === '' || trim((string) $operator) === '')) {
            throw new \InvalidArgumentException('merchant_verified needs an evidence reference and the operator who verified the store consents.');
        }
        $attestation = $merchant ? [
            'reason' => mb_substr(trim($reason), 0, 255),
            'evidence_ref' => mb_substr(trim((string) $evidenceRef), 0, 255),
            'operator' => mb_substr(trim((string) $operator), 0, 120),
            'actor' => trim($actor->type.':'.($actor->id ?? ''), ':'),
            'attested_at' => CarbonImmutable::now()->toIso8601String(),
        ] : null;
        $tenantId = $this->context->requireTenant();
        DB::transaction(function () use ($tenantId, $mode, $reason, $actor, $attestation) {
            $from = $this->mode();
            DB::statement(
                'INSERT INTO tenant_settings (tenant_id, consent_strategy, consent_strategy_attestation, created_at, updated_at) VALUES (?, ?, ?::jsonb, now(), now())
                 ON CONFLICT (tenant_id) DO UPDATE SET consent_strategy = EXCLUDED.consent_strategy,
                    consent_strategy_attestation = EXCLUDED.consent_strategy_attestation, updated_at = now()',
                [$tenantId, $mode->value, $attestation === null ? null : json_encode($attestation, JSON_UNESCAPED_UNICODE)],
            );
            $this->audit->record(AuditAction::ConsentStrategyChanged, 'tenant', $tenantId, array_filter([
                'from' => $from->value, 'to' => $mode->value, 'reason' => mb_substr($reason, 0, 255),
                'evidence_ref' => $attestation['evidence_ref'] ?? null, 'operator' => $attestation['operator'] ?? null,
            ], fn ($v) => $v !== null), $actor);
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

    /**
     * Hours a successful store permission history sync keeps
     * merchant_verified decisions current.
     */
    public static function merchantMaxAgeHours(): ?int
    {
        $value = config('consent.merchant.max_age_hours');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public static function maxAgeHours(): ?int
    {
        $value = config('consent.reconciliation.max_age_hours');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Inputs of the eligibility query: [operational, stale, per-row freshness
     * applies, merchant_verified].
     *
     * @return array{0: bool, 1: bool, 2: bool, 3: bool}
     */
    public function eligibilityFlags(): array
    {
        $mode = $this->mode();
        if ($mode === ConsentStrategyMode::DirectApi) {
            return [$this->directApiAvailable(), false, true, false];
        }
        $c = $this->core($mode);

        return [$c['operational'], $c['stale'], false, $mode === ConsentStrategyMode::MerchantVerified];
    }

    /**
     * Readiness facts of a strategy.
     *
     * @return array{mode: ConsentStrategyMode, direct: bool, via: ViaStatus, max_age: ?int, applied: ?object, age_hours: ?float, stale: bool, operational: bool, blocker: ?string, merchant?: array<string, mixed>}
     */
    private function core(ConsentStrategyMode $mode): array
    {
        if ($mode === ConsentStrategyMode::MerchantVerified) {
            $m = $this->merchant();

            return ['mode' => $mode, 'direct' => false, 'via' => $this->via->status(), 'max_age' => self::maxAgeHours(), 'applied' => $this->lastApplied(), 'age_hours' => null,
                'stale' => false, 'operational' => $m['blocker'] === null, 'blocker' => $m['blocker'], 'merchant' => $m];
        }
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

    /**
     * merchant_verified facts: the attestation, the store integration and
     * the age of its last successful permission history sync.
     *
     * @return array{attestation: ?array<string, mixed>, commerce_connected: bool, last_sync_at: ?string, sync_age_hours: ?float, max_age_hours: ?int, blocker: ?string}
     */
    private function merchant(): array
    {
        $tenantId = $this->context->requireTenant();
        $raw = DB::table('tenant_settings')->where('tenant_id', $tenantId)->value('consent_strategy_attestation');
        $attestation = $raw === null ? null : (array) json_decode((string) $raw, true);
        $commerce = TenantIntegration::query()->where('capability', 'commerce')->first();
        $lastSync = $commerce === null ? null : DB::table('integration_sync_cursors')->where('tenant_id', $tenantId)->where('integration_id', $commerce->id)
            ->where('entity', ConsentSynchronizer::ENTITY_TICIMAX_HISTORY)->value('last_success_at');
        $age = $lastSync === null ? null : max(0, round(CarbonImmutable::parse((string) $lastSync, 'UTC')->diffInMinutes(CarbonImmutable::now(), true) / 60, 1));
        $maxAge = self::merchantMaxAgeHours();
        $connected = $commerce !== null && $commerce->status === 'connected';

        return [
            'attestation' => $attestation === [] ? null : $attestation,
            'commerce_connected' => $connected,
            'last_sync_at' => $lastSync,
            'sync_age_hours' => $age,
            'max_age_hours' => $maxAge,
            'blocker' => match (true) {
                $attestation === null || $attestation === [] => 'MERCHANT_ATTESTATION_MISSING',
                ! $connected => 'MERCHANT_COMMERCE_NOT_CONNECTED',
                $maxAge === null => 'MERCHANT_CONSENT_POLICY_MISSING',
                $lastSync === null => 'MERCHANT_CONSENT_SYNC_MISSING',
                $age > $maxAge => 'MERCHANT_CONSENT_STALE',
                default => null,
            },
        ];
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
            'source_label' => match ($mode) {
                ConsentStrategyMode::DirectApi => 'İYS API',
                ConsentStrategyMode::ViaReconciliation => 'ViA + İYS mutabakatı',
                ConsentStrategyMode::MerchantVerified => 'Mağaza izni (operasyonel doğrulama)',
            },
            // merchant_verified only: never an İYS or ViA verification.
            'merchant' => $c['merchant'] ?? null,
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
