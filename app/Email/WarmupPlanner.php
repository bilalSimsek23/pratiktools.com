<?php

namespace App\Email;

/**
 * Proposes a warm-up schedule for a new sending domain/account. A plan
 * only: nothing is scheduled or sent.
 *
 * Start with the most engaged recipients (recent buyers, recent opens or
 * clicks), small daily volumes, and grow by a factor only while the
 * previous day stayed healthy. Each day is capped by the provider quota
 * (minus the transactional reserve) and the remaining pool. When the
 * last day's hard bounce or complaint rate crossed the guard thresholds
 * the next day repeats the volume (or halves it at twice the threshold).
 */
final class WarmupPlanner
{
    /**
     * @param  int  $pool  eligible marketing recipients
     * @param  float  $max24h  provider quota (0 = unknown)
     * @param  array{bounce_rate?: ?float, complaint_rate?: ?float, last_volume?: ?int}  $lastDay
     * @return list<array{day: int, volume: int, segment: string}>
     */
    public function propose(int $pool, float $max24h, array $lastDay = [], int $start = 500, float $growth = 1.5, int $maxDays = 30): array
    {
        $cap = $max24h > 0 ? (int) floor($max24h * (1 - (float) config('email.guardrails.quota_reserve'))) : PHP_INT_MAX;
        $volume = $this->nextVolume($start, $lastDay, $growth);
        $remaining = $pool;
        $plan = [];

        for ($day = 1; $day <= $maxDays && $remaining > 0; $day++) {
            $today = min($volume, $cap, $remaining);
            $plan[] = ['day' => $day, 'volume' => $today, 'segment' => $this->segment($pool - $remaining, $pool)];
            $remaining -= $today;
            $volume = (int) ceil($volume * $growth);
        }

        return $plan;
    }

    /**
     * @param  array{bounce_rate?: ?float, complaint_rate?: ?float, last_volume?: ?int}  $lastDay
     */
    public function nextVolume(int $start, array $lastDay, float $growth): int
    {
        $last = (int) ($lastDay['last_volume'] ?? 0);
        if ($last <= 0) {
            return $start;
        }
        $bounce = (float) ($lastDay['bounce_rate'] ?? 0);
        $complaint = (float) ($lastDay['complaint_rate'] ?? 0);
        $maxBounce = (float) config('email.guardrails.max_bounce_rate');
        $maxComplaint = (float) config('email.guardrails.max_complaint_rate');

        return match (true) {
            $bounce > 2 * $maxBounce || $complaint > 2 * $maxComplaint => max(100, intdiv($last, 2)),
            $bounce > $maxBounce || $complaint > $maxComplaint => $last,
            default => (int) ceil($last * $growth),
        };
    }

    private function segment(int $done, int $pool): string
    {
        $share = $pool > 0 ? $done / $pool : 1;

        return match (true) {
            $share < 0.1 => 'buyers_last_90d_and_recent_engagement',
            $share < 0.35 => 'buyers_last_12m',
            $share < 0.7 => 'engaged_last_12m',
            default => 'remaining_consented',
        };
    }
}
