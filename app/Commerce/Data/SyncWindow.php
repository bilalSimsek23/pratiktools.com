<?php

namespace App\Commerce\Data;

use Carbon\CarbonImmutable;

/**
 * Time window for one sync run. A full window has no lower bound (or the
 * configured backfill start); a delta window starts at the previous
 * successful window end minus an overlap.
 */
final readonly class SyncWindow
{
    public function __construct(
        public ?CarbonImmutable $from,
        public CarbonImmutable $to,
        public bool $full,
    ) {}

    public static function full(?CarbonImmutable $from, CarbonImmutable $to): self
    {
        return new self($from, $to, true);
    }

    public static function delta(CarbonImmutable $from, CarbonImmutable $to): self
    {
        return new self($from, $to, false);
    }
}
