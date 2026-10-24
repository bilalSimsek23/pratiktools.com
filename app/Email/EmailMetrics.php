<?php

namespace App\Email;

use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read-only e-mail metrics from our own tables (never a provider call):
 * connection, sender identities, sending mode, last known provider quota,
 * today's counts, bounce and complaint rates, last provider error, usage.
 * No secrets, no recipient addresses.
 */
final class EmailMetrics
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EmailSendGuard $guard,
        private readonly EmailProviderResolver $providers,
        private readonly UnsubscribeTokens $unsubscribe,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(TenantIntegration $integration): array
    {
        $tenantId = $this->context->requireTenant();
        $account = (array) ($integration->settings['account'] ?? []);
        $checkedAt = isset($account['checked_at']) ? CarbonImmutable::parse((string) $account['checked_at']) : null;
        $messages = DB::table('email_messages')->where('tenant_id', $tenantId)->where('integration_id', $integration->id);
        $window = (int) config('email.guardrails.window_days');
        $since = now()->subDays($window);

        $sentWindow = (clone $messages)->where('status', 'sent')->where('sent_at', '>=', $since)->count();
        $events = DB::table('email_events')->where('tenant_id', $tenantId)->where('provider', $integration->provider)->where('occurred_at', '>=', $since)
            ->selectRaw("count(*) FILTER (WHERE event_type = 'EMAIL_BOUNCED' AND bounce_class = 'hard') AS hard, count(*) FILTER (WHERE event_type = 'EMAIL_BOUNCED' AND bounce_class <> 'hard') AS other_bounces, count(*) FILTER (WHERE event_type = 'EMAIL_COMPLAINT') AS complaints, count(*) FILTER (WHERE event_type = 'EMAIL_DELIVERED') AS delivered")
            ->first();
        $today = DB::table('email_usage_daily')->where('tenant_id', $tenantId)->where('provider', $integration->provider)->whereRaw('day = current_date')->first();
        $lastFailure = (clone $messages)->where('status', 'failed')->orderByDesc('updated_at')->first(['error_code', 'updated_at']);

        return [
            'provider' => $integration->provider,
            'connected' => $integration->status === 'connected',
            'status' => $integration->status,
            'role' => $integration->settings['email_role'] ?? 'primary',
            'region' => $integration->settings['region'] ?? null,
            'scopes' => $integration->scopes,
            'send_scope' => in_array(EmailSendGuard::SCOPE, (array) $integration->scopes, true),
            'sending_mode' => $this->guard->mode()->value,
            'capabilities' => $this->providers->capabilities($integration)->values(),
            'unsubscribe_configured' => $this->unsubscribe->available(),
            'event_destination' => ['configuration_set' => $integration->settings['configuration_set'] ?? null, 'sns_topics' => count((array) ($integration->settings['sns_topic_arns'] ?? [])),
                'subscription' => $integration->settings['sns_subscription']['state'] ?? null],
            'account' => [
                'checked_at' => $checkedAt?->toIso8601String(),
                'stale' => $checkedAt === null || $checkedAt->lt(now()->subMinutes((int) config('email.ses.account_max_age_minutes'))),
                'production_access' => $account['production_access'] ?? null,
                'sending_enabled' => $account['sending_enabled'] ?? null,
                'enforcement_status' => $account['enforcement_status'] ?? null,
                'max_24h_send' => $account['max_24h_send'] ?? null,
                'max_send_rate' => $account['max_send_rate'] ?? null,
                'sent_last_24h' => $account['sent_last_24h'] ?? null,
            ],
            'sender_identities' => DB::table('email_sender_identities')->where('tenant_id', $tenantId)->where('integration_id', $integration->id)->orderBy('from_email')
                ->get(['from_email', 'domain', 'verification_status', 'dkim_status', 'mail_from_status', 'spf_status', 'dmarc_status', 'last_checked_at'])->map(fn ($r) => (array) $r)->all(),
            'today' => ['attempted' => (int) ($today->emails_attempted ?? 0), 'sent' => (int) ($today->emails_sent ?? 0), 'delivered' => (int) ($today->emails_delivered ?? 0),
                'bounced' => (int) ($today->emails_bounced ?? 0), 'complaints' => (int) ($today->complaints ?? 0)],
            'window_days' => $window,
            'sent_in_window' => $sentWindow,
            'delivered_in_window' => (int) $events->delivered,
            'hard_bounce_rate' => $sentWindow > 0 ? round((int) $events->hard / $sentWindow, 5) : null,
            'complaint_rate' => $sentWindow > 0 ? round((int) $events->complaints / $sentWindow, 5) : null,
            'other_bounces' => (int) $events->other_bounces,
            'messages_by_status' => (clone $messages)->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
            'blocked_by_reason' => (clone $messages)->where('status', 'blocked')->selectRaw('block_reason, count(*) AS n')->groupBy('block_reason')->pluck('n', 'block_reason')->map(fn ($n) => (int) $n)->all(),
            'last_provider_error' => $integration->last_error ?? $lastFailure?->error_code,
            'last_failure_at' => $lastFailure?->updated_at,
            'test_recipients' => DB::table('test_recipients')->where('tenant_id', $tenantId)->where('channel', 'email')->where('active', true)->count(),
            'usage_30d' => $this->usage($integration->provider, 30),
        ];
    }

    /**
     * Provider-neutral usage totals (for reports and later billing).
     *
     * @return array<string, int>
     */
    public function usage(string $provider, int $days): array
    {
        $row = DB::table('email_usage_daily')->where('tenant_id', $this->context->requireTenant())->where('provider', $provider)
            ->where('day', '>=', now()->subDays($days)->toDateString())
            ->selectRaw(implode(', ', array_map(fn ($f) => "coalesce(sum({$f}), 0) AS {$f}", EmailUsage::FIELDS)))->first();

        return array_map('intval', (array) $row);
    }

    /**
     * Health without a provider call; a provider problem is a warning.
     *
     * @return array<string, mixed>
     */
    public function health(?TenantIntegration $integration): array
    {
        if ($integration === null) {
            return ['status' => 'not_configured'];
        }
        $account = (array) ($integration->settings['account'] ?? []);
        $paused = ($account['enforcement_status'] ?? null) === 'SHUTDOWN' || ($account['sending_enabled'] ?? true) === false;

        return [
            'status' => $integration->status !== 'connected' || $paused || ($account['enforcement_status'] ?? null) === 'PROBATION' ? 'warn' : 'ok',
            'integration_status' => $integration->status,
            'account_checked_at' => $account['checked_at'] ?? null,
            'enforcement_status' => $account['enforcement_status'] ?? null,
            'sending_mode' => $this->guard->mode()->value,
        ];
    }
}
