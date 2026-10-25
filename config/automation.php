<?php

return [

    /*
    | The automation tick (trigger scan, time-based evaluation, due runs) is
    | off unless AUTOMATION_SCHEDULE_ENABLED=true. Automations never send:
    | email/push steps only record planned actions.
    */
    'schedule_enabled' => (bool) env('AUTOMATION_SCHEDULE_ENABLED', false),

    // Bounded work per tenant per tick, so no tenant starves the others.
    'tick' => [
        'events_per_source' => 1000,
        'runs_per_tick' => 200,
        'time_budget_seconds' => 45,
        'lease_seconds' => 120,
        // Time-based automations are re-evaluated at most this often.
        'time_trigger_interval_minutes' => 60,
        'time_trigger_batch' => 2000,
    ],

    // Events older than this (or flagged backfill) never start a run.
    'max_trigger_age_hours' => 72,

    // Ticimax status codes that mean "completed" (Teslim Edildi).
    'completed_order_status_codes' => [7],

    // A cart counts as still open while the sync keeps seeing it.
    'cart_seen_within_minutes' => 180,

    /*
    | Push marketing frequency cap per customer, across automations. The
    | e-mail cap is email.guardrails.frequency_cap (one source for campaigns
    | and automations). Transactional messages are never counted or capped.
    */
    'frequency_cap' => [
        'push' => ['per_day' => 2, 'per_week' => 6],
    ],

    'segments' => [
        'max_depth' => 4,
        'max_rules' => 25,
        'max_list_values' => 100,
        // Preview/dry-run statements are read-only and time-limited.
        'statement_timeout_ms' => 20000,
    ],

    'dry_run' => [
        'event_lookback_days' => 14,
        'max_candidates' => 20000,
        'sample_traces' => 10,
    ],

];
