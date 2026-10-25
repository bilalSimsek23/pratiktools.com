<?php

namespace App\Operations;

use App\Consent\SendEligibility;
use App\Consent\Sync\ConsentSynchronizer;
use App\Email\EmailSendGuard;
use App\Email\UnsubscribeTokens;
use App\Push\PushSendGuard;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Integration status for the panel, from stored state only (no provider
 * call) and never a secret: only whether one is stored.
 */
final class IntegrationStatus
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SendEligibility $eligibility,
        private readonly EmailSendGuard $email,
        private readonly PushSendGuard $push,
        private readonly UnsubscribeTokens $unsubscribe,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $tenantId = $this->context->requireTenant();
        $rows = DB::table('tenant_integrations')->where('tenant_id', $tenantId)->get(['id', 'provider', 'capability', 'status', 'scopes', 'settings', 'last_verified_at', 'last_error',
            DB::raw('encrypted_secret IS NOT NULL AS has_secret')])->keyBy('provider');
        $settings = fn (string $p) => (array) json_decode((string) ($rows[$p]->settings ?? '{}'), true);
        $lastRun = fn (?string $id, ?string $entity = null) => $id === null ? null : DB::table('integration_sync_runs')->where('tenant_id', $tenantId)->where('integration_id', $id)
            ->when($entity !== null, fn ($q) => $q->where('entity', $entity))->orderByDesc('started_at')->first(['entity', 'status', 'started_at', 'finished_at', 'error_category']);
        $base = fn (string $p) => isset($rows[$p])
            ? ['configured' => true, 'connected' => $rows[$p]->status === 'connected', 'status' => $rows[$p]->status, 'vault_secret' => (bool) $rows[$p]->has_secret,
                'scopes' => (array) json_decode((string) $rows[$p]->scopes, true), 'last_verified_at' => $rows[$p]->last_verified_at, 'last_error' => $rows[$p]->last_error]
            : ['configured' => false, 'connected' => false, 'status' => 'not_configured', 'vault_secret' => false];

        $ses = $settings('amazon_ses');
        $account = (array) ($ses['account'] ?? []);
        $pushProfiles = DB::table('push_profiles')->where('tenant_id', $tenantId);
        $iys = $rows['iys'] ?? null;
        $iysSuccess = $iys === null ? null : DB::table('integration_sync_cursors')->where('tenant_id', $tenantId)->where('integration_id', $iys->id)
            ->where('entity', ConsentSynchronizer::ENTITY_IYS_STATUS)->value('last_success_at');

        return [
            'ticimax' => $base('ticimax') + ['last_sync' => $lastRun($rows['ticimax']->id ?? null), 'capabilities' => ['commerce', 'coupon (not used)']],
            'onesignal' => $base('onesignal') + [
                'app_id' => $settings('onesignal')['app_id'] ?? null,
                'subscriptions' => DB::table('push_subscriptions')->where('tenant_id', $tenantId)->whereNull('removed_at')->count(),
                'subscribed' => DB::table('push_subscriptions')->where('tenant_id', $tenantId)->whereNull('removed_at')->where('status', 'subscribed')->count(),
                'external_id_coverage' => ['with' => (clone $pushProfiles)->whereNotNull('external_id')->count(), 'total' => (clone $pushProfiles)->count()],
                'sending_mode' => $this->push->mode()->value,
                'last_sync' => $lastRun($rows['onesignal']->id ?? null),
            ],
            'amazon_ses' => $base('amazon_ses') + [
                'region' => $ses['region'] ?? null,
                'production_access' => $account['production_access'] ?? null,
                'enforcement_status' => $account['enforcement_status'] ?? null,
                'quota' => ['max_24h' => $account['max_24h_send'] ?? null, 'max_rate' => $account['max_send_rate'] ?? null, 'sent_last_24h' => $account['sent_last_24h'] ?? null],
                'account_checked_at' => $account['checked_at'] ?? null,
                'identities' => DB::table('email_sender_identities')->where('tenant_id', $tenantId)->get(['from_email', 'domain', 'verification_status', 'dkim_status', 'mail_from_status', 'dmarc_status'])->map(fn ($r) => (array) $r)->all(),
                'sending_mode' => $this->email->mode()->value,
                'unsubscribe_key' => $this->unsubscribe->available() ? 'CONFIGURED' : 'MISSING',
            ],
            'iys' => $base('iys') + [
                'consent_available' => $this->eligibility->iysAvailable(),
                'last_successful_status_sync' => $iysSuccess,
                'freshness_limit_hours' => (int) config('consent.eligibility.iys_max_age_hours'),
            ],
        ];
    }
}
