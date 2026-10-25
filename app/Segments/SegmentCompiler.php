<?php

namespace App\Segments;

use App\Consent\SendEligibility;
use Illuminate\Support\Str;

/**
 * Validates a segment definition and compiles it to a parameterized SQL
 * condition over customers c LEFT JOIN customer_stats s.
 *
 * Definition (JSON):
 *   {"all": [node, …]} | {"any": [node, …]} | {"not": node}
 *   {"field": "order_count", "op": "gte", "value": 2}
 *
 * Limits: depth ≤ segments.max_depth, rules ≤ segments.max_rules, lists ≤
 * segments.max_list_values. Unknown fields/operators and mistyped values
 * are rejected; nothing from the definition reaches SQL except as a bound
 * parameter.
 */
final class SegmentCompiler
{
    public function __construct(private readonly SendEligibility $eligibility) {}

    /**
     * @param  array<string, mixed>  $definition
     * @return array{where: string, bindings: list<mixed>, ctes: array<string, array{sql: string, bindings: list<mixed>}>, rules: int}
     */
    public function compile(array $definition): array
    {
        $state = ['rules' => 0, 'ctes' => []];
        $bindings = [];
        $where = $this->node($definition, 1, '$', $bindings, $state);

        return ['where' => $where, 'bindings' => $bindings, 'ctes' => $state['ctes'], 'rules' => $state['rules']];
    }

    /**
     * Full statement selecting matching customer ids (optionally narrowed).
     *
     * @param  array<string, mixed>  $definition
     * @param  list<mixed>  $extraBindings
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function select(array $definition, string $tenantId, string $columns = 'c.id', string $extraWhere = '', array $extraBindings = []): array
    {
        $c = $this->compile($definition);
        $with = [];
        $withBindings = [];
        foreach ($c['ctes'] as $name => $cte) {
            $with[] = "{$name} AS MATERIALIZED ({$cte['sql']})";
            array_push($withBindings, ...$cte['bindings']);
        }

        $sql = ($with === [] ? '' : 'WITH '.implode(', ', $with).' ')
            ."SELECT {$columns} FROM customers c LEFT JOIN customer_stats s ON s.tenant_id = c.tenant_id AND s.customer_id = c.id
               WHERE c.tenant_id = ? AND ({$c['where']}) {$extraWhere}";

        return ['sql' => $sql, 'bindings' => [...$withBindings, $tenantId, ...$c['bindings'], ...$extraBindings]];
    }

    /**
     * @param  list<mixed>  $bindings
     * @param  array{rules: int, ctes: array<string, mixed>}  $state
     */
    private function node(mixed $node, int $depth, string $path, array &$bindings, array &$state): string
    {
        if ($depth > (int) config('automation.segments.max_depth')) {
            throw new SegmentRuleError('TOO_DEEP', $path, 'Nesting is too deep.');
        }
        if (! is_array($node) || $node === []) {
            throw new SegmentRuleError('INVALID_NODE', $path, 'A node must be an object.');
        }

        foreach (['all' => ' AND ', 'any' => ' OR '] as $key => $glue) {
            if (array_key_exists($key, $node)) {
                if (count($node) !== 1 || ! is_array($node[$key]) || $node[$key] === [] || ! array_is_list($node[$key])) {
                    throw new SegmentRuleError('INVALID_NODE', $path, "\"{$key}\" needs a non-empty list and nothing else.");
                }
                $parts = [];
                foreach ($node[$key] as $i => $child) {
                    $parts[] = $this->node($child, $depth + 1, "{$path}.{$key}[{$i}]", $bindings, $state);
                }

                return '('.implode($glue, $parts).')';
            }
        }
        if (array_key_exists('not', $node)) {
            if (count($node) !== 1) {
                throw new SegmentRuleError('INVALID_NODE', $path, '"not" takes one node and nothing else.');
            }

            return 'NOT ('.$this->node($node['not'], $depth + 1, "{$path}.not", $bindings, $state).')';
        }

        return $this->rule($node, $path, $bindings, $state);
    }

    /**
     * @param  array<array-key, mixed>  $rule
     * @param  list<mixed>  $bindings
     * @param  array{rules: int, ctes: array<string, mixed>}  $state
     */
    private function rule(array $rule, string $path, array &$bindings, array &$state): string
    {
        if (++$state['rules'] > (int) config('automation.segments.max_rules')) {
            throw new SegmentRuleError('TOO_MANY_RULES', $path, 'Too many rules.');
        }
        if (array_diff(array_keys($rule), ['field', 'op', 'value']) !== [] || ! is_string($rule['field'] ?? null) || ! is_string($rule['op'] ?? null)) {
            throw new SegmentRuleError('INVALID_NODE', $path, 'A rule has exactly field, op and (optionally) value.');
        }
        $field = SegmentFields::get($rule['field']) ?? throw new SegmentRuleError('UNKNOWN_FIELD', $path, 'Unknown field.');
        $op = $rule['op'];
        if (! in_array($op, SegmentFields::OPERATORS[$field['type']], true)) {
            throw new SegmentRuleError('UNKNOWN_OPERATOR', $path, "Operator not allowed for a {$field['type']} field.");
        }
        $value = $rule['value'] ?? null;

        if (isset($field['cte']) && ! isset($state['ctes'][$field['cte']])) {
            $state['ctes'][$field['cte']] = $this->cte($field['cte']);
        }

        return match ($field['type']) {
            'int', 'money' => $this->number((string) $field['sql'], $op, $value, $field['type'] === 'money', $path, $bindings),
            'datetime' => $this->datetime((string) $field['sql'], $op, $value, $path, $bindings),
            'bool' => $this->bool($field, $value, $path),
            'string' => $this->string((string) $field['sql'], $op, $value, $path, $bindings),
            'ids' => $this->ids((string) $field['in'], $value, $path, $bindings),
            'enum' => $this->consentStatus($op, $value, (array) $field['values'], $path, $bindings),
            default => throw new SegmentRuleError('UNKNOWN_FIELD', $path, 'Unknown field type.'),
        };
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private function number(string $sql, string $op, mixed $value, bool $money, string $path, array &$bindings): string
    {
        $max = $money ? 1_000_000_000 : 1_000_000;
        $check = fn (mixed $v) => (is_int($v) || ($money && is_float($v))) && $v >= 0 && $v <= $max
            ? $v : throw new SegmentRuleError('INVALID_VALUE', $path, "Expected a number between 0 and {$max}.");

        if ($op === 'between') {
            if (! is_array($value) || count($value) !== 2 || ! array_is_list($value)) {
                throw new SegmentRuleError('INVALID_VALUE', $path, 'between needs [min, max].');
            }
            array_push($bindings, $check($value[0]), $check($value[1]));

            return "({$sql} BETWEEN ? AND ?)";
        }
        $bindings[] = $check($value);

        return "({$sql} ".['eq' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$op].' ?)';
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private function datetime(string $sql, string $op, mixed $value, string $path, array &$bindings): string
    {
        if ($op === 'is_null' || $op === 'not_null') {
            if ($value !== null) {
                throw new SegmentRuleError('INVALID_VALUE', $path, "{$op} takes no value.");
            }

            return "({$sql} IS ".($op === 'is_null' ? '' : 'NOT ').'NULL)';
        }
        if (! is_int($value) || $value < 0 || $value > 3650) {
            throw new SegmentRuleError('INVALID_VALUE', $path, 'Expected a number of days between 0 and 3650.');
        }
        $bindings[] = $value;

        return $op === 'within_last_days' ? "({$sql} >= now() - make_interval(days => ?))" : "({$sql} < now() - make_interval(days => ?))";
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function bool(array $field, mixed $value, string $path): string
    {
        if (! is_bool($value)) {
            throw new SegmentRuleError('INVALID_VALUE', $path, 'Expected true or false.');
        }
        $expr = isset($field['in']) ? "c.id IN ({$field['in']})" : "({$field['sql']})";

        return $value ? $expr : "NOT {$expr}";
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private function string(string $sql, string $op, mixed $value, string $path, array &$bindings): string
    {
        $one = fn (mixed $v) => is_string($v) && $v !== '' && mb_strlen($v) <= 100 ? mb_strtolower($v) : throw new SegmentRuleError('INVALID_VALUE', $path, 'Expected a short text.');
        if ($op === 'eq') {
            $bindings[] = $one($value);

            return "({$sql} = ?)";
        }
        $bindings[] = '{'.implode(',', array_map(fn ($v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $one($v)).'"', $this->list($value, $path))).'}';

        return "({$sql} = ANY(?::text[]))";
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private function ids(string $in, mixed $value, string $path, array &$bindings): string
    {
        $ids = array_map(fn ($v) => is_string($v) && Str::isUuid($v) ? strtolower($v) : throw new SegmentRuleError('INVALID_VALUE', $path, 'Expected ids.'), $this->list($value, $path));
        $bindings[] = '{'.implode(',', $ids).'}';

        return "c.id IN ({$in})";
    }

    /**
     * İYS (individual) e-mail status of any of the customer's addresses;
     * NONE = no İYS answer at all.
     *
     * @param  list<string>  $allowed
     * @param  list<mixed>  $bindings
     */
    private function consentStatus(string $op, mixed $value, array $allowed, string $path, array &$bindings): string
    {
        $values = $op === 'eq' ? [$value] : $this->list($value, $path);
        foreach ($values as $v) {
            if (! in_array($v, $allowed, true)) {
                throw new SegmentRuleError('INVALID_VALUE', $path, 'Expected one of '.implode(', ', $allowed).'.');
            }
        }
        $statuses = array_values(array_diff($values, ['NONE']));
        $iys = "SELECT 1 FROM recipient_customers rc JOIN consents cs ON cs.tenant_id = rc.tenant_id AND cs.recipient_id = rc.recipient_id
                WHERE rc.tenant_id = c.tenant_id AND rc.customer_id = c.id AND cs.channel = 'EMAIL' AND cs.recipient_type = 'INDIVIDUAL' AND cs.source_provider = 'IYS'";
        $parts = [];
        if ($statuses !== []) {
            $bindings[] = '{'.implode(',', $statuses).'}';
            $parts[] = "EXISTS ({$iys} AND cs.status = ANY(?::text[]))";
        }
        if (in_array('NONE', $values, true)) {
            $parts[] = "NOT EXISTS ({$iys})";
        }

        return '('.implode(' OR ', $parts).')';
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value, string $path): array
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value) || count($value) > (int) config('automation.segments.max_list_values')) {
            throw new SegmentRuleError('INVALID_VALUE', $path, 'Expected a non-empty list within the size limit.');
        }

        return $value;
    }

    /**
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function cte(string $name): array
    {
        return match ($name) {
            // Customers with at least one e-mail address the Consent Engine
            // allows (same SQL as single decisions: İYS fail-closed).
            'email_eligible' => [
                'sql' => "SELECT DISTINCT rc.customer_id FROM recipient_customers rc JOIN ({$this->eligibility->sql()}) e ON e.id = rc.recipient_id WHERE e.reason = 'ALLOWED'",
                'bindings' => $this->eligibility->bindings(),
            ],
            default => throw new SegmentRuleError('UNKNOWN_FIELD', '$', 'Unknown derived table.'),
        };
    }
}
