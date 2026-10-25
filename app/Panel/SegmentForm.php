<?php

namespace App\Panel;

use App\Segments\SegmentFields;
use App\Segments\SegmentRuleError;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Form-based segment builder ↔ the F5 rule DSL. Users pick whitelisted
 * fields and operators from lists; values are typed by the field. Limits
 * (depth, rule count) are enforced again by the compiler in the backend.
 *
 * Form shape: match (all|any), groups[g][match], groups[g][rows][r][field|op|value].
 */
final class SegmentForm
{
    public const FIELDS = [
        'last_order_at' => 'Son sipariş tarihi', 'first_order_at' => 'İlk sipariş tarihi', 'order_count' => 'Toplam sipariş',
        'total_revenue' => 'Toplam harcama (TL)', 'average_order_value' => 'Ortalama sepet (TL)', 'last_cart_at' => 'Son sepet tarihi',
        'last_interaction_at' => 'Son etkileşim', 'member_since' => 'Üyelik tarihi', 'created_at' => 'Kayıt tarihi', 'is_guest' => 'Misafir müşteri',
        'city' => 'Şehir', 'has_open_cart' => 'Açık sepeti var', 'newsletter_subscriber' => 'Bülten abonesi', 'push_subscribed' => 'Push abonesi',
        'email_marketing_eligible' => 'E-posta pazarlama izni uygun', 'email_consent_status' => 'İYS e-posta durumu',
        'purchased_category' => 'Satın aldığı kategori', 'cart_contains_category' => 'Sepetindeki kategori',
        'purchased_product' => 'Satın aldığı ürün (ürün kodu)', 'cart_contains_product' => 'Sepetindeki ürün (ürün kodu)',
    ];

    public const OPERATORS = [
        'eq' => '=', 'neq' => '≠', 'gt' => '>', 'gte' => '≥', 'lt' => '<', 'lte' => '≤', 'between' => 'arasında (min,max)',
        'within_last_days' => 'son N gün içinde', 'before_days_ago' => 'N günden eski', 'is_null' => 'boş', 'not_null' => 'dolu',
        'is' => 'evet/hayır', 'in' => 'şunlardan biri (virgülle)',
    ];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> DSL definition
     */
    public function toDefinition(array $input): array
    {
        $groups = [];
        foreach ((array) ($input['groups'] ?? []) as $g) {
            $rules = [];
            foreach ((array) ($g['rows'] ?? []) as $row) {
                $field = (string) ($row['field'] ?? '');
                if ($field === '') {
                    continue;
                }
                $rules[] = $this->rule($field, (string) ($row['op'] ?? ''), $row['value'] ?? null);
            }
            if ($rules !== []) {
                $groups[] = [($g['match'] ?? 'all') === 'any' ? 'any' : 'all' => $rules];
            }
        }
        if ($groups === []) {
            throw new SegmentRuleError('INVALID_NODE', '$', 'En az bir kural ekleyin.');
        }

        return [($input['match'] ?? 'all') === 'any' ? 'any' : 'all' => $groups];
    }

    /**
     * @return array{field: string, op: string, value?: mixed}
     */
    private function rule(string $field, string $op, mixed $raw): array
    {
        $def = SegmentFields::get($field) ?? throw new SegmentRuleError('UNKNOWN_FIELD', $field, 'Bilinmeyen alan.');
        $raw = is_string($raw) ? trim($raw) : $raw;
        $list = fn () => array_values(array_filter(array_map('trim', explode(',', (string) $raw)), fn ($v) => $v !== ''));

        $value = match ($def['type']) {
            'int' => $op === 'between' ? array_map('intval', $list()) : (int) $raw,
            'money' => $op === 'between' ? array_map(fn ($v) => (float) str_replace(',', '.', $v), $list()) : (float) str_replace(',', '.', (string) $raw),
            'datetime' => in_array($op, ['is_null', 'not_null'], true) ? null : (int) $raw,
            'bool' => in_array((string) $raw, ['1', 'true', 'evet'], true),
            'string' => $op === 'in' ? $list() : (string) $raw,
            'enum' => $op === 'in' ? $list() : (string) $raw,
            'ids' => $this->ids($field, $list()),
            default => $raw,
        };
        if ($def['type'] === 'money' && ! is_array($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        return $value === null ? ['field' => $field, 'op' => $op] : ['field' => $field, 'op' => $op, 'value' => $value];
    }

    /**
     * Category names or product codes → ids of this tenant.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private function ids(string $field, array $values): array
    {
        $tenantId = $this->context->requireTenant();
        $ids = str_contains($field, 'category')
            ? DB::table('categories')->where('tenant_id', $tenantId)->where(fn ($q) => $q->whereIn('name', $values)->orWhereIn('external_id', $values))->pluck('id')
            : DB::table('products')->where('tenant_id', $tenantId)->whereIn('external_id', $values)->pluck('id');

        return $ids->map(fn ($id) => (string) $id)->values()->all() ?: throw new SegmentRuleError('INVALID_VALUE', $field, 'Eşleşen kategori/ürün bulunamadı.');
    }

    /**
     * Readable description of a stored definition.
     *
     * @param  array<string, mixed>  $node
     */
    public static function describe(array $node): string
    {
        foreach (['all' => ' VE ', 'any' => ' VEYA '] as $k => $glue) {
            if (isset($node[$k])) {
                return implode($glue, array_map(fn ($c) => count((array) $c) === 1 && (isset($c['all']) || isset($c['any'])) ? '('.self::describe($c).')' : self::describe($c), (array) $node[$k]));
            }
        }
        if (isset($node['not'])) {
            return 'DEĞİL ('.self::describe((array) $node['not']).')';
        }
        $label = self::FIELDS[$node['field'] ?? ''] ?? (string) ($node['field'] ?? '?');
        $op = self::OPERATORS[$node['op'] ?? ''] ?? (string) ($node['op'] ?? '');
        $value = $node['value'] ?? null;
        $value = is_array($value) ? (str_contains((string) $node['field'], 'category') || str_contains((string) $node['field'], 'product') ? count($value).' seçim' : implode(', ', $value))
            : (is_bool($value) ? ($value ? 'evet' : 'hayır') : (string) $value);
        if (in_array($node['op'] ?? null, ['within_last_days', 'before_days_ago'], true)) {
            return "{$label} ".($node['op'] === 'within_last_days' ? "son {$value} gün içinde" : "{$value} günden eski");
        }

        return trim("{$label} {$op} {$value}");
    }
}
