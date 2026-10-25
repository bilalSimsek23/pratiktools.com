<?php

namespace App\Automation;

use Carbon\CarbonImmutable;

/**
 * Outcome of one step: pass, wait until, exit (with reason and metric
 * category), blocked-but-continue (an intent step whose channel is not
 * eligible), or intent (a planned action to record).
 */
final readonly class StepDecision
{
    /**
     * @param  array<string, mixed>  $intent
     */
    private function __construct(
        public string $outcome,
        public ?string $reason = null,
        public ?string $category = null,
        public ?CarbonImmutable $until = null,
        public array $intent = [],
    ) {}

    public static function pass(?string $reason = null): self
    {
        return new self('passed', $reason);
    }

    public static function wait(CarbonImmutable $until): self
    {
        return new self('waiting', until: $until);
    }

    public static function exit(string $reason, ?string $category = null): self
    {
        return new self('exited', $reason, $category);
    }

    public static function blocked(string $reason, ?string $category): self
    {
        return new self('blocked', $reason, $category);
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    public static function intent(array $intent): self
    {
        return new self('intent_created', intent: $intent);
    }
}
