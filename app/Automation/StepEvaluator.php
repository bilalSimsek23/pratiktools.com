<?php

namespace App\Automation;

use App\Segments\Segments;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Evaluates one step of a run against the current data. Reads only: the
 * caller (RunProcessor, or DryRun inside a read-only transaction) decides
 * what to persist. Eligibility is always evaluated now, at action time,
 * never taken from when the run started.
 */
final class StepEvaluator
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ActionEligibility $eligibility,
        private readonly Segments $segments,
        private readonly RecommendationContext $recommendations,
    ) {}

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $run  customer_id, triggered_at, trigger_type, context (by reference: waits, intents, available products)
     * @param  array<string, int>  $pending  dry-run frequency ledger
     */
    public function evaluate(array $step, array &$run, CarbonImmutable $now, array $pending = []): StepDecision
    {
        $p = (array) ($step['params'] ?? []);
        $customer = (string) $run['customer_id'];

        return match ($step['type']) {
            'delay' => $this->delay($step['key'], $p, $run, $now),
            'condition' => $this->condition((string) $p['check'], $p, $run, $now),
            'segment_check' => $this->segments->matches($this->segments->definition((string) $p['segment_id']), $customer)
                ? StepDecision::pass() : StepDecision::exit('not_in_segment'),
            'consent_check' => $this->check($p['channel'] === 'email' ? $this->eligibility->email($customer, 'marketing', $pending) : $this->eligibility->push($customer, 'marketing', $pending), true),
            'frequency_check' => $this->frequency((string) $p['channel'], $customer, $pending),
            'create_email_intent' => $this->intent('email', $step, $p, $run, $pending),
            'create_push_intent' => ($p['only_if_no_intent'] ?? false) && ($run['context']['intents'] ?? 0) > 0
                ? StepDecision::pass('skipped_intent_exists')
                : $this->intent('push', $step, $p, $run, $pending),
            'exit' => StepDecision::exit((string) ($p['reason'] ?? 'done')),
            default => throw new AutomationDefinitionError('UNKNOWN_STEP', 'Unknown step type.'),
        };
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $run
     */
    private function delay(string $key, array $p, array &$run, CarbonImmutable $now): StepDecision
    {
        $until = $run['context']['waits'][$key] ?? null;
        if ($until === null) {
            $base = ($p['from'] ?? 'step') === 'trigger' ? CarbonImmutable::parse($run['triggered_at']) : $now;
            $until = $base->addMinutes((int) $p['minutes'])->toIso8601String();
            $run['context']['waits'][$key] = $until;
        }
        $until = CarbonImmutable::parse($until);

        return $now->gte($until) ? StepDecision::pass() : StepDecision::wait($until);
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $run
     */
    private function condition(string $check, array $p, array &$run, CarbonImmutable $now): StepDecision
    {
        $tenantId = $this->context->requireTenant();
        $ctx = (array) $run['context'];

        switch ($check) {
            case 'no_order_since_trigger':
                $since = $ctx['since'] ?? $run['triggered_at'];
                $ordered = DB::table('orders')->where('tenant_id', $tenantId)->where('customer_id', $run['customer_id'])->where('placed_at', '>', $since)->exists();

                return $ordered ? StepDecision::exit('ordered_since_trigger') : StepDecision::pass();

            case 'cart_still_open':
                $cart = DB::table('carts')->where('tenant_id', $tenantId)->where('id', $ctx['cart_id'] ?? null)->first(['item_count', 'last_seen_at', 'content_hash', 'customer_id']);
                if ($cart === null || (int) $cart->item_count === 0) {
                    return StepDecision::exit('cart_gone');
                }
                if ($cart->customer_id !== $run['customer_id']) {
                    return StepDecision::exit('cart_owner_changed', 'identity');
                }
                if (isset($ctx['cart_hash']) && $cart->content_hash !== $ctx['cart_hash']) {
                    return StepDecision::exit('cart_changed');
                }

                return CarbonImmutable::parse($cart->last_seen_at)->lt($now->subMinutes((int) config('automation.cart_seen_within_minutes')))
                    ? StepDecision::exit('cart_no_longer_seen') : StepDecision::pass();

            case 'cart_items_available':
                // Current catalog state, not the cart snapshot: only items that
                // are active and in stock now; prices are not carried over.
                // Items not yet linked to the catalog resolve by external id.
                $available = DB::select(
                    'SELECT DISTINCT p.id AS product_id FROM cart_items ki
                     LEFT JOIN product_variants v ON v.tenant_id = ki.tenant_id AND (v.id = ki.variant_id OR (ki.variant_id IS NULL AND v.external_id = ki.variant_external_id))
                     JOIN products p ON p.tenant_id = ki.tenant_id AND (p.id = COALESCE(ki.product_id, v.product_id) OR (ki.product_id IS NULL AND v.id IS NULL AND p.external_id = ki.product_external_id))
                     WHERE ki.tenant_id = ? AND ki.cart_id = ? AND p.is_active AND (CASE WHEN v.id IS NULL THEN p.total_stock > 0 ELSE v.is_active AND v.stock > 0 END)',
                    [$tenantId, $ctx['cart_id'] ?? null]);
                if ($available === []) {
                    return StepDecision::exit('items_unavailable');
                }
                $run['context']['available_products'] = array_map(fn ($r) => (string) $r->product_id, $available);

                return StepDecision::pass();

            default: // customer_rule
                return $this->segments->matches((array) $p['rule'], (string) $run['customer_id']) ? StepDecision::pass() : StepDecision::exit('rule_not_met');
        }
    }

    /**
     * @param  array{ok: bool, reason: string, category: ?string, recipient: ?string}  $result
     */
    private function check(array $result, bool $exitOnFail): StepDecision
    {
        return $result['ok'] ? StepDecision::pass() : ($exitOnFail ? StepDecision::exit(strtolower($result['reason']), $result['category']) : StepDecision::blocked($result['reason'], $result['category']));
    }

    /**
     * @param  array<string, int>  $pending
     */
    private function frequency(string $channel, string $customer, array $pending): StepDecision
    {
        $result = $channel === 'email' ? $this->eligibility->email($customer, 'marketing', $pending) : $this->eligibility->push($customer, 'marketing', $pending);

        return $result['category'] === 'frequency' ? StepDecision::exit('frequency_capped', 'frequency') : StepDecision::pass();
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $run
     * @param  array<string, int>  $pending
     */
    private function intent(string $channel, array $step, array $p, array &$run, array $pending): StepDecision
    {
        $customer = (string) $run['customer_id'];
        $type = (string) $p['message_type'];
        $result = $channel === 'email' ? $this->eligibility->email($customer, $type, $pending) : $this->eligibility->push($customer, $type, $pending);
        if (! $result['ok']) {
            return ($p['on_ineligible'] ?? 'continue') === 'exit' ? StepDecision::exit(strtolower($result['reason']), $result['category']) : StepDecision::blocked($result['reason'], $result['category']);
        }

        $ctx = (array) $run['context'];

        return StepDecision::intent([
            'channel' => $channel,
            'message_type' => $type,
            'recipient_ref' => $result['recipient'],
            'payload' => array_filter([
                'template_version_id' => $p['template_version_id'] ?? null,
                'trigger' => $run['trigger_type'],
                'cart_id' => $ctx['cart_id'] ?? null,
                'order_id' => $ctx['order_id'] ?? null,
                'product_ids' => $ctx['available_products'] ?? null,
                'recommendations' => $this->recommendations->forCustomer($customer, $ctx['cart_id'] ?? null),
                // Price, stock and content are resolved by the dispatcher at send
                // time; nothing here may be shown to the customer as a fact.
                'resolve_at_dispatch' => ['price', 'stock', 'content', 'consent'],
            ], fn ($v) => $v !== null),
        ]);
    }
}
