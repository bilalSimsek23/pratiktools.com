<?php

namespace App\Leads;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class LeadMetrics
{
    public const COUNTERS = ['submissions', 'accepted', 'duplicate', 'invalid', 'bot_suspected', 'rate_limited', 'origin_refused', 'consent_checked', 'consent_unchecked', 'new_recipients'];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, int>  $counts
     */
    public function add(string $formId, array $counts): void
    {
        $counts = array_intersect_key(array_filter($counts), array_flip(self::COUNTERS));
        if ($counts === []) {
            return;
        }
        $cols = array_keys($counts);
        DB::statement(
            'INSERT INTO lead_metrics_daily (tenant_id, form_id, day, '.implode(', ', $cols).') VALUES (?, ?, current_date, '.implode(', ', array_fill(0, count($cols), '?')).')
             ON CONFLICT (tenant_id, form_id, day) DO UPDATE SET '.implode(', ', array_map(fn ($c) => "{$c} = lead_metrics_daily.{$c} + EXCLUDED.{$c}", $cols)),
            [$this->context->requireTenant(), $formId, ...array_values($counts)],
        );
    }
}
