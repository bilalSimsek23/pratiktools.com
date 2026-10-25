<?php

namespace App\Messaging;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Marketing frequency cap per tenant, channel and recipient over rolling
 * 24 h and 7 day windows, shared by campaigns (EmailSendGuard) and
 * automations. It counts sent marketing e-mail and planned marketing
 * actions; transactional messages are never counted and never capped.
 *
 * Recipient: e-mail → consent recipient id; push → customer id.
 */
final class FrequencyCap
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{per_day: int, per_week: int}
     */
    public static function limits(string $channel): array
    {
        $limits = $channel === 'email' ? (array) config('email.guardrails.frequency_cap') : (array) config("automation.frequency_cap.{$channel}");

        return ['per_day' => (int) ($limits['per_day'] ?? 0), 'per_week' => (int) ($limits['per_week'] ?? 0)];
    }

    /**
     * @param  int  $pending  extra messages already decided but not yet stored (dry-run)
     */
    public function exceeded(string $channel, string $recipient, int $pending = 0): bool
    {
        $limits = self::limits($channel);

        return $this->count($channel, $recipient, 24) + $pending >= $limits['per_day']
            || $this->count($channel, $recipient, 24 * 7) + $pending >= $limits['per_week'];
    }

    public function count(string $channel, string $recipient, int $hours): int
    {
        $tenantId = $this->context->requireTenant();
        $since = now()->subHours($hours);

        $planned = DB::table('automation_planned_actions')->where('tenant_id', $tenantId)->where('channel', $channel)->where('message_type', 'marketing')
            ->where('status', 'planned')->where('created_at', '>=', $since)
            ->where($channel === 'email' ? 'recipient_ref' : 'customer_id', $recipient)->count();

        if ($channel !== 'email') {
            return $planned;
        }

        return $planned + DB::table('email_messages')->where('tenant_id', $tenantId)->where('recipient_id', $recipient)
            ->where('message_type', 'marketing')->where('status', 'sent')->where('sent_at', '>=', $since)->count();
    }
}
