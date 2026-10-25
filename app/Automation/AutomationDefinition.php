<?php

namespace App\Automation;

use App\Segments\SegmentCompiler;
use Illuminate\Support\Str;

/**
 * Validates an automation definition (trigger + linear steps + settings).
 * Only whitelisted trigger types, step types, conditions and parameters;
 * customer rules use the segment DSL.
 *
 * Steps run in order. A step either passes, waits (delay), creates an
 * intent, or ends the run (exit / failed condition / ineligible with
 * on_ineligible=exit). Intent steps never send anything.
 */
final class AutomationDefinition
{
    public const STEP_TYPES = ['delay', 'condition', 'segment_check', 'consent_check', 'frequency_check', 'create_email_intent', 'create_push_intent', 'exit'];

    public const CONDITIONS = ['no_order_since_trigger', 'cart_still_open', 'cart_items_available', 'customer_rule'];

    private const MAX_STEPS = 20;

    public function __construct(private readonly SegmentCompiler $segments) {}

    /**
     * @param  array<string, mixed>  $trigger
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>  $settings
     */
    public function validate(array $trigger, array $steps, array $settings = []): void
    {
        $type = TriggerType::tryFrom((string) ($trigger['type'] ?? '')) ?? throw new AutomationDefinitionError('UNKNOWN_TRIGGER', 'Unknown trigger type.');
        $params = (array) ($trigger['params'] ?? []);
        $this->only($trigger, ['type', 'params'], 'trigger');

        match ($type) {
            TriggerType::NoPurchaseFor => $this->int($params, 'days', 1, 3650) && $this->optionalInt($params, 'catch_up_days', 1, 90),
            TriggerType::SegmentPeriodic => $this->uuid($params, 'segment_id') && $this->int($params, 'reentry_days', 1, 365),
            TriggerType::ConsentChanged => $this->enum($params, 'channel', ['EMAIL', 'SMS', 'CALL', 'PUSH'], optional: true)
                && $this->enum($params, 'new_status', ['GRANTED', 'REVOKED', 'PENDING', 'UNKNOWN'], optional: true),
            default => true,
        };
        if (isset($params['audience'])) {
            $this->segments->compile((array) $params['audience']);
        }

        if ($steps === [] || count($steps) > self::MAX_STEPS) {
            throw new AutomationDefinitionError('INVALID_STEPS', 'Between 1 and '.self::MAX_STEPS.' steps are required.');
        }
        $keys = [];
        foreach ($steps as $i => $step) {
            $key = (string) ($step['key'] ?? '');
            if (preg_match('/^[a-z0-9_]{1,48}$/', $key) !== 1 || isset($keys[$key])) {
                throw new AutomationDefinitionError('INVALID_STEP_KEY', "Step {$i}: keys are unique [a-z0-9_]{1,48}.");
            }
            $keys[$key] = true;
            $this->only($step, ['key', 'type', 'params'], "step {$key}");
            $p = (array) ($step['params'] ?? []);

            match ((string) ($step['type'] ?? '')) {
                'delay' => $this->int($p, 'minutes', 1, 90 * 24 * 60) && $this->enum($p, 'from', ['step', 'trigger'], optional: true),
                'condition' => $this->condition($p, $key),
                'segment_check' => $this->uuid($p, 'segment_id'),
                'consent_check', 'frequency_check' => $this->enum($p, 'channel', ['email', 'push']),
                'create_email_intent' => $this->enum($p, 'message_type', ['marketing', 'transactional']) && $this->enum($p, 'on_ineligible', ['continue', 'exit'], optional: true)
                    && (! isset($p['template_version_id']) || $this->uuid($p, 'template_version_id')),
                'create_push_intent' => $this->enum($p, 'message_type', ['marketing', 'transactional']) && $this->enum($p, 'on_ineligible', ['continue', 'exit'], optional: true)
                    && (! isset($p['only_if_no_intent']) || is_bool($p['only_if_no_intent']) || throw new AutomationDefinitionError('INVALID_PARAM', "{$key}: only_if_no_intent is boolean.")),
                'exit' => $this->enum($p, 'reason', ['done', 'manual'], optional: true),
                default => throw new AutomationDefinitionError('UNKNOWN_STEP', "Step {$key}: unknown type."),
            };
        }

        if (isset($settings['message_type']) && ! in_array($settings['message_type'], ['marketing', 'transactional'], true)) {
            throw new AutomationDefinitionError('INVALID_PARAM', 'settings.message_type is marketing or transactional.');
        }
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function condition(array $p, string $key): bool
    {
        $check = $p['check'] ?? null;
        if (! in_array($check, self::CONDITIONS, true)) {
            throw new AutomationDefinitionError('UNKNOWN_CONDITION', "{$key}: unknown condition.");
        }
        if ($check === 'customer_rule') {
            $this->segments->compile((array) ($p['rule'] ?? []));
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  list<string>  $allowed
     */
    private function only(array $a, array $allowed, string $where): void
    {
        if (array_diff(array_keys($a), $allowed) !== []) {
            throw new AutomationDefinitionError('UNKNOWN_KEY', "{$where}: unexpected keys.");
        }
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function int(array $p, string $name, int $min, int $max): bool
    {
        if (! is_int($p[$name] ?? null) || $p[$name] < $min || $p[$name] > $max) {
            throw new AutomationDefinitionError('INVALID_PARAM', "{$name} must be an integer {$min}..{$max}.");
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function optionalInt(array $p, string $name, int $min, int $max): bool
    {
        return ! array_key_exists($name, $p) || $this->int($p, $name, $min, $max);
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function uuid(array $p, string $name): bool
    {
        if (! is_string($p[$name] ?? null) || ! Str::isUuid($p[$name])) {
            throw new AutomationDefinitionError('INVALID_PARAM', "{$name} must be an id.");
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  list<string>  $values
     */
    private function enum(array $p, string $name, array $values, bool $optional = false): bool
    {
        if ($optional && ! array_key_exists($name, $p)) {
            return true;
        }
        if (! in_array($p[$name] ?? null, $values, true)) {
            throw new AutomationDefinitionError('INVALID_PARAM', "{$name} must be one of ".implode(', ', $values).'.');
        }

        return true;
    }
}
