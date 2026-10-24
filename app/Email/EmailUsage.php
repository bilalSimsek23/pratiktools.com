<?php

namespace App\Email;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Provider-neutral daily usage counters (attempted, sent, delivered,
 * bounced, complaints, bytes, provider requests). A usage report, not a
 * bill: provider pricing is not computed here.
 */
final class EmailUsage
{
    public const FIELDS = ['emails_attempted', 'emails_sent', 'emails_delivered', 'emails_bounced', 'complaints', 'bytes_sent', 'provider_requests'];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, int>  $counts  field => increment
     */
    public function add(string $provider, array $counts): void
    {
        $counts = array_intersect_key(array_filter($counts), array_flip(self::FIELDS));
        if ($counts === []) {
            return;
        }

        $columns = array_keys($counts);
        DB::statement(
            'INSERT INTO email_usage_daily (tenant_id, provider, day, '.implode(', ', $columns).') VALUES (?, ?, current_date, '.implode(', ', array_fill(0, count($columns), '?')).')
             ON CONFLICT (tenant_id, provider, day) DO UPDATE SET '.implode(', ', array_map(fn ($c) => "{$c} = email_usage_daily.{$c} + EXCLUDED.{$c}", $columns)),
            [$this->context->requireTenant(), $provider, ...array_values($counts)],
        );
    }
}
