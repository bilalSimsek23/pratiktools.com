<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Commerce\CommerceSourceFactory;
use App\Commerce\Contracts\LooksUpRecords;
use App\Commerce\Data\RecordPage;
use App\Commerce\Data\SyncWindow;
use App\Commerce\Identity\ContactNormalizer;
use App\Console\Commands\Concerns\FindsCommerceIntegration;
use App\Console\Commands\Concerns\RunsInTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only reconciliation of stored data against the source:
 * counts where the source can count cheaply, and a field-by-field check of
 * random members, products and orders re-fetched by id. Contact fields
 * are compared but never printed.
 */
final class CompareCommerceData extends Command
{
    use FindsCommerceIntegration, RunsInTenantContext;

    protected $signature = 'commerce:compare {--tenant= : Tenant slug} {--sample=10} {--order-days=7} {--cart-hours=48}';

    protected $description = 'Compare stored commerce data with the source (read-only)';

    /** @var array<string, int> */
    private array $mismatches = [];

    public function handle(CommerceSourceFactory $sources, ContactNormalizer $normalizer): int
    {
        return $this->inTenant(function () use ($sources, $normalizer) {
            $source = $sources->for($this->commerceIntegration(), 'reconciliation', Actor::console($this->getName()));
            $now = CarbonImmutable::now()->subMinutes(5);
            $sample = max(1, (int) $this->option('sample'));

            $this->info('Counts');
            $rows = [];

            $categories = count($source->categories()->records);
            $rows[] = ['categories (all)', $categories, DB::table('categories')->count()];

            if ($source instanceof LooksUpRecords) {
                $rows[] = ['products (all)', $source->productCount() ?? '?', DB::table('products')->count()];
            }

            $from = $now->subDays((int) $this->option('order-days'));
            $sourceOrders = $this->drain(fn ($t) => $source->orders(SyncWindow::full($from, $now), $t));
            $rows[] = ["orders placed last {$this->option('order-days')}d", count($sourceOrders), DB::table('orders')->whereBetween('placed_at', [$from, $now])->count()];

            $cartFrom = $now->subHours((int) $this->option('cart-hours'));
            $sourceCarts = $this->drain(fn ($t) => $source->carts(SyncWindow::full($cartFrom, $now), $t));
            $rows[] = ["carts created last {$this->option('cart-hours')}h", count($sourceCarts), DB::table('carts')->whereBetween('source_created_at', [$cartFrom, $now])->count()];

            $rows[] = ['members (identities)', $this->lastFullRead('customers'), DB::table('customer_identities')->where('type', 'commerce_member')->count()];
            $rows[] = ['newsletter subscribers', '-', DB::table('customer_identities')->where('type', 'newsletter_subscriber')->count()];
            $this->table(['what', 'source', 'stored'], $rows);

            $missingOrders = array_diff(array_map(fn ($o) => $o->externalId, $sourceOrders), DB::table('orders')->whereIn('external_id', array_map(fn ($o) => $o->externalId, $sourceOrders) ?: [''])->pluck('external_id')->all());
            if ($missingOrders !== []) {
                $this->warn(count($missingOrders).' source orders of the window are not stored (newer than the last sync window?).');
            }

            if (! $source instanceof LooksUpRecords) {
                $this->warn('Source does not support record lookup; field comparison skipped.');

                return self::SUCCESS;
            }

            $this->info("\nField-by-field (random sample of {$sample} each)");
            $this->compareMembers($source, $normalizer, $sample);
            $this->compareProducts($source, $sample);
            $this->compareOrders($source, $sample);

            $this->info("\nMismatching fields: ".($this->mismatches === [] ? 'none' : json_encode($this->mismatches)));

            return self::SUCCESS;
        });
    }

    /**
     * @param  callable(?string): RecordPage<mixed>  $fetch
     * @return list<mixed>
     */
    private function drain(callable $fetch): array
    {
        $records = [];
        $token = null;
        do {
            $page = $fetch($token);
            array_push($records, ...$page->records);
            $token = $page->nextPageToken;
        } while ($token !== null);

        return $records;
    }

    private function lastFullRead(string $entity): string
    {
        $run = DB::table('integration_sync_runs')->where('entity', $entity)->where('mode', 'full')->where('status', 'succeeded')->orderByDesc('started_at')->first();

        return $run === null ? '-' : "{$run->records_read} read in last full run (members + subscribers)";
    }

    private function compareMembers(LooksUpRecords $source, ContactNormalizer $normalizer, int $sample): void
    {
        $stored = DB::table('customer_identities as i')->join('customers as c', 'c.id', '=', 'i.customer_id')
            ->where('i.type', 'commerce_member')->inRandomOrder()->limit($sample)
            ->get(['i.value as member_id', 'c.email', 'c.phone', 'c.first_name', 'c.last_name', 'c.birth_date', 'c.gender', 'c.city', 'c.is_active', 'c.member_since', 'c.source_consent']);

        $rows = [];
        foreach ($stored as $c) {
            $r = $source->member($c->member_id);
            if ($r === null) {
                $rows[] = [$c->member_id, 'NOT FOUND AT SOURCE'];

                continue;
            }
            $consent = json_decode($c->source_consent, true) ?: [];
            $rows[] = [$c->member_id, $this->diff('member', [
                'email' => [$normalizer->email($c->email) ?? $c->email, $normalizer->email($r->email) ?? $r->email, true],
                'phone' => [$c->phone, $normalizer->phone($r->phone), true],
                'first_name' => [$c->first_name, $r->firstName, true],
                'last_name' => [$c->last_name, $r->lastName, true],
                'birth_date' => [$c->birth_date, $r->birthDate?->toDateString(), true],
                'gender' => [$c->gender, $r->gender],
                'city' => [$c->city, $r->city],
                'is_active' => [$c->is_active, $r->isActive],
                'member_since' => [$this->ts($c->member_since), $r->memberSince?->utc()->toDateTimeString()],
                'email_opt_in' => [$consent['email_opt_in'] ?? null, $r->emailOptIn],
                'sms_opt_in' => [$consent['sms_opt_in'] ?? null, $r->smsOptIn],
            ])];
        }
        $this->table(['member', 'result'], $rows);
    }

    private function compareProducts(LooksUpRecords $source, int $sample): void
    {
        $stored = DB::table('products')->inRandomOrder()->limit($sample)->get();

        $rows = [];
        foreach ($stored as $p) {
            $r = $source->product($p->external_id);
            if ($r === null) {
                $rows[] = [$p->external_id, 'NOT FOUND AT SOURCE'];

                continue;
            }
            $variants = DB::table('product_variants')->where('product_id', $p->id)->get()->keyBy('external_id');
            $sourceVariants = collect($r->variants)->keyBy('externalId');
            $variantDiffs = 0;
            foreach ($sourceVariants as $id => $v) {
                $s = $variants[$id] ?? null;
                if ($s === null || (bool) $s->is_active !== $v->isActive || (string) $s->sku !== (string) $v->sku || (float) $s->stock !== $v->stock || (float) $s->sale_price !== (float) $v->salePrice || $s->color !== $v->color || $s->size !== $v->size) {
                    $variantDiffs++;
                }
            }

            $rows[] = [$p->external_id, $this->diff('product', [
                'name' => [$p->name, $r->name],
                'brand' => [$p->brand, $r->brand],
                'is_active' => [$p->is_active, $r->isActive],
                'total_stock' => [(float) $p->total_stock, $r->totalStock],
                'url' => [$p->url, $r->url],
                'active_variants' => [$variants->where('is_active', true)->count(), count(array_filter($r->variants, fn ($v) => $v->isActive))],
                'variant_fields' => [0, $variantDiffs],
                'categories' => [DB::table('product_categories')->where('product_id', $p->id)->count(), count(array_unique($r->categoryExternalIds))],
            ])];
        }
        $this->table(['product', 'result'], $rows);
    }

    private function compareOrders(LooksUpRecords $source, int $sample): void
    {
        $stored = DB::table('orders')->inRandomOrder()->limit($sample)->get();

        $rows = [];
        foreach ($stored as $o) {
            $r = $source->order($o->external_id);
            if ($r === null) {
                $rows[] = [$o->external_id, 'NOT FOUND AT SOURCE'];

                continue;
            }
            $items = DB::table('order_items')->where('order_id', $o->id)->get();
            $rows[] = [$o->external_id.($o->is_marketplace ? ' (mp)' : ''), $this->diff('order', [
                'number' => [$o->order_number, $r->number],
                'status' => [$o->status_code === null ? null : (int) $o->status_code, $r->statusCode],
                'status_label' => [$o->status_label, $r->statusLabel],
                'channel' => [$o->channel, $r->channel],
                'placed_at' => [$this->ts($o->placed_at), $r->placedAt->utc()->toDateTimeString()],
                'net_total' => [round((float) $o->net_total, 2), round($r->netTotal, 2)],
                'grand_total' => [round((float) $o->grand_total, 2), round($r->grandTotal, 2)],
                'currency' => [$o->currency, $r->currency],
                'member' => [$o->external_member_id, $r->memberExternalId],
                'email' => [$o->email, $r->email, true],
                'items' => [$items->count(), count($r->items)],
                'items_total' => [round((float) $items->sum('row_total'), 2), round(array_sum(array_map(fn ($i) => $i->rowTotal, $r->items)), 2)],
            ])];
        }
        $this->table(['order', 'result'], $rows);
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed, 2?: bool}>  $fields  name => [stored, source, sensitive]
     */
    private function diff(string $type, array $fields): string
    {
        $out = [];
        foreach ($fields as $name => $pair) {
            [$stored, $source] = $pair;
            $same = is_float($source) || is_float($stored) ? abs((float) $stored - (float) $source) < 0.005 : (string) $this->scalar($stored) === (string) $this->scalar($source);
            if (! $same) {
                $this->mismatches["{$type}.{$name}"] = ($this->mismatches["{$type}.{$name}"] ?? 0) + 1;
                $out[] = ($pair[2] ?? false) ? "{$name} differs" : "{$name}: ".json_encode($this->scalar($stored), JSON_UNESCAPED_UNICODE).' vs '.json_encode($this->scalar($source), JSON_UNESCAPED_UNICODE);
            }
        }

        return $out === [] ? 'ok ('.count($fields).' fields)' : implode('; ', $out);
    }

    private function scalar(mixed $value): mixed
    {
        return is_bool($value) ? ($value ? '1' : '0') : $value;
    }

    private function ts(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->toDateTimeString();
    }
}
