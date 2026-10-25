<?php

namespace App\Dispatch;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * What happened to a dispatch, from our own tables: messages handed to the
 * pipeline, their status and provider events (delivered, bounce,
 * complaint). Counts only.
 */
final class DispatchObservability
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array<string, mixed>
     */
    public function for(object $dispatch): array
    {
        $tenantId = $this->context->requireTenant();
        if ($dispatch->channel === 'email') {
            $m = DB::table('email_messages')->where('tenant_id', $tenantId)->where('dispatch_id', $dispatch->id);
            $events = DB::table('email_events as e')->join('email_messages as m', fn ($j) => $j->on('m.id', '=', 'e.message_id')->on('m.tenant_id', '=', 'e.tenant_id'))
                ->where('m.tenant_id', $tenantId)->where('m.dispatch_id', $dispatch->id)->selectRaw('e.event_type, count(*) AS n')->groupBy('e.event_type')->pluck('n', 'event_type');

            return [
                'messages' => (clone $m)->count(),
                'by_status' => (clone $m)->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
                'blocked_reasons' => (clone $m)->where('status', 'blocked')->selectRaw('block_reason, count(*) AS n')->groupBy('block_reason')->pluck('n', 'block_reason')->map(fn ($n) => (int) $n)->all(),
                'events' => $events->map(fn ($n) => (int) $n)->all(),
                'duration_seconds' => $dispatch->started_at && $dispatch->completed_at ? strtotime((string) $dispatch->completed_at) - strtotime((string) $dispatch->started_at) : null,
            ];
        }
        $p = DB::table('push_send_requests')->where('tenant_id', $tenantId)->where('dispatch_id', $dispatch->id);

        return [
            'messages' => (clone $p)->count(),
            'by_status' => (clone $p)->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
            'blocked_reasons' => (clone $p)->whereIn('status', ['blocked', 'failed', 'not_sent'])->selectRaw('error_code, count(*) AS n')->groupBy('error_code')->pluck('n', 'error_code')->map(fn ($n) => (int) $n)->all(),
            'events' => [],
            'duration_seconds' => $dispatch->started_at && $dispatch->completed_at ? strtotime((string) $dispatch->completed_at) - strtotime((string) $dispatch->started_at) : null,
        ];
    }
}
