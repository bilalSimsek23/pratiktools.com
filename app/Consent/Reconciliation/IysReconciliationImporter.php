<?php

namespace App\Consent\Reconciliation;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Consent\ConsentChannel;
use App\Consent\ConsentConflicts;
use App\Consent\ConsentSourceProvider;
use App\Consent\ConsentStatus;
use App\Consent\ConsentStore;
use App\Consent\RecipientDirectory;
use App\Consent\RecipientType;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * İYS change reports in two steps, tenant scoped:
 *
 *  analyze  reads the file once (streamed, in chunks), classifies every row
 *           against the current consent state and stores the result per
 *           row (address hash + recipient id only). Changes no consent.
 *  apply    re-classifies the stored rows against the then-current state
 *           and records the official ONAY/RET (source IYS_RECONCILIATION)
 *           in one transaction. A batch is applied once.
 *
 * The same file (sha256) is one batch per tenant: re-uploading it
 * re-analyses an unapplied batch and is refused once applied, so a report
 * never changes consent twice; a change already applied from another
 * report is "duplicate".
 *
 * Outcomes: onay, ret, unchanged, conflict (older than the known official
 * state, or ONAY for a locally blocked address), invalid,
 * unknown_recipient (no recipient is created from a report),
 * marketplace_excluded, duplicate. A conflict never lifts a local block;
 * it is recorded in consent_conflicts.
 */
final class IysReconciliationImporter
{
    public const COUNTERS = ['rows', 'onay', 'ret', 'unchanged', 'conflict', 'invalid', 'unknown_recipient', 'marketplace_excluded', 'duplicate'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly IysReconciliationParsers $parsers,
        private readonly RecipientDirectory $recipients,
        private readonly ConsentStore $consents,
        private readonly ConsentConflicts $conflicts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{batch_id: string, status: string, counts: array<string, int>, error: ?string}
     */
    public function analyze(ReconciliationFile $file, CarbonImmutable $periodEnd, ?CarbonImmutable $periodStart, Actor $actor): array
    {
        $tenantId = $this->context->requireTenant();
        if ($periodEnd->greaterThan(CarbonImmutable::today()) || ($periodStart !== null && $periodStart->greaterThan($periodEnd))) {
            throw new ReconciliationError('INVALID_PERIOD', 'The report period must end today or earlier and start before it ends.');
        }

        $batchId = DB::transaction(function () use ($tenantId, $file, $periodEnd, $periodStart, $actor) {
            $existing = DB::table('iys_reconciliation_batches')->where('tenant_id', $tenantId)->where('file_sha256', $file->sha256())->lockForUpdate()->first(['id', 'status']);
            if ($existing?->status === 'applied') {
                throw new ReconciliationError('ALREADY_APPLIED', 'This report has already been applied.');
            }
            $values = ['original_filename' => $file->originalName, 'file_size' => $file->size(), 'period_start' => $periodStart?->toDateString(),
                'period_end' => $periodEnd->toDateString(), 'status' => 'analyzing', 'error' => null, 'dry_run_counts' => '{}', 'format' => $this->parsers->configuredFormat()];
            if ($existing !== null) {
                DB::table('iys_reconciliation_batches')->where('tenant_id', $tenantId)->where('id', $existing->id)->update($values);

                return (string) $existing->id;
            }
            $id = (string) Str::uuid7();
            DB::table('iys_reconciliation_batches')->insert($values + ['id' => $id, 'tenant_id' => $tenantId, 'source' => 'operator_upload', 'file_sha256' => $file->sha256(),
                'created_by' => self::by($actor), 'created_at' => now()]);

            return $id;
        });

        try {
            $parser = $this->parsers->parser();
            $counts = DB::transaction(fn () => $this->classifyFile($batchId, $parser->parse($file)));
            $status = 'analyzed';
            $error = null;
        } catch (ReconciliationError $e) {
            [$counts, $status, $error] = [[], $e->reason === 'FORMAT_NOT_CONFIGURED' ? 'unsupported' : 'failed', $e->reason];
        } catch (Throwable $e) {
            report($e);
            [$counts, $status, $error] = [[], 'failed', 'ANALYSIS_FAILED'];
        }

        DB::table('iys_reconciliation_batches')->where('tenant_id', $tenantId)->where('id', $batchId)
            ->update(['status' => $status, 'error' => $error, 'dry_run_counts' => json_encode((object) $counts), 'analyzed_at' => now()]);
        $this->audit->record(AuditAction::IysReconciliationAnalyzed, 'iys_reconciliation_batch', $batchId, ['status' => $status, 'error' => $error, 'counts' => $counts], $actor);

        return ['batch_id' => $batchId, 'status' => $status, 'counts' => $counts, 'error' => $error];
    }

    /**
     * @return array<string, int>
     */
    public function apply(string $batchId, Actor $actor): array
    {
        $tenantId = $this->context->requireTenant();

        return DB::transaction(function () use ($tenantId, $batchId, $actor) {
            $batch = DB::table('iys_reconciliation_batches')->where('tenant_id', $tenantId)->where('id', $batchId)->lockForUpdate()->first(['id', 'status', 'dry_run_counts'])
                ?? throw new ReconciliationError('NOT_FOUND', 'Unknown batch.');
            if ($batch->status !== 'analyzed') {
                throw new ReconciliationError($batch->status === 'applied' ? 'ALREADY_APPLIED' : 'NOT_ANALYZED', 'Only an analysed, unapplied batch can be applied.');
            }

            $counts = array_fill_keys(self::COUNTERS, 0);
            $memory = [];
            $after = 0;
            $chunk = (int) config('consent.reconciliation.chunk');
            do {
                $rows = DB::table('iys_consent_changes')->where('tenant_id', $tenantId)->where('batch_id', $batchId)->where('row_number', '>', $after)
                    ->orderBy('row_number')->limit($chunk)->get();
                $pending = [];
                foreach ($rows as $row) {
                    $after = (int) $row->row_number;
                    $counts['rows']++;
                    if (in_array($row->outcome, ['invalid', 'unknown_recipient', 'marketplace_excluded'], true) || $row->row_key === null) {
                        $counts[$row->outcome]++;

                        continue;
                    }
                    $pending[] = $row;
                }
                $decided = $this->decide($batchId, array_map(fn ($r) => [
                    'recipient_id' => (string) $r->recipient_id, 'channel' => (string) $r->channel, 'type' => (string) $r->recipient_type,
                    'status' => (string) $r->status, 'at' => CarbonImmutable::parse((string) $r->occurred_at), 'row_key' => (string) $r->row_key,
                ], $pending), $memory);

                foreach ($pending as $i => $row) {
                    [$outcome, $detail] = $decided[$i];
                    $counts[$outcome]++;
                    $this->record($row, $outcome, $detail, $batchId);
                    DB::table('iys_consent_changes')->where('tenant_id', $tenantId)->where('id', $row->id)->update(['outcome' => $outcome, 'detail' => $detail, 'applied' => true]);
                }
            } while ($rows->count() === $chunk);

            // Within-file duplicates were not stored; keep them in the totals.
            $dryRun = (array) json_decode((string) $batch->dry_run_counts, true);
            $skipped = (int) ($dryRun['rows'] ?? 0) - $counts['rows'];
            $counts['duplicate'] += max(0, $skipped);
            $counts['rows'] += max(0, $skipped);

            DB::table('iys_reconciliation_batches')->where('tenant_id', $tenantId)->where('id', $batchId)
                ->update(['status' => 'applied', 'applied_counts' => json_encode($counts), 'applied_by' => self::by($actor), 'applied_at' => now()]);
            $this->audit->record(AuditAction::IysReconciliationApplied, 'iys_reconciliation_batch', $batchId, ['counts' => $counts], $actor);

            return $counts;
        });
    }

    /**
     * @param  iterable<int, IysConsentChange|InvalidReconciliationRow>  $rows
     * @return array<string, int>
     */
    private function classifyFile(string $batchId, iterable $rows): array
    {
        $tenantId = $this->context->requireTenant();
        DB::table('iys_consent_changes')->where('tenant_id', $tenantId)->where('batch_id', $batchId)->delete();
        $counts = array_fill_keys(self::COUNTERS, 0);
        $memory = [];
        $seen = [];
        $buffer = [];
        $max = (int) config('consent.reconciliation.max_rows');
        $chunk = (int) config('consent.reconciliation.chunk');

        foreach ($rows as $row) {
            if (++$counts['rows'] > $max) {
                throw new ReconciliationError('TOO_MANY_ROWS', 'The report has more rows than allowed.');
            }
            $buffer[] = $row;
            if (count($buffer) >= $chunk) {
                $this->classifyChunk($batchId, $buffer, $counts, $memory, $seen);
                $buffer = [];
            }
        }
        if ($buffer !== []) {
            $this->classifyChunk($batchId, $buffer, $counts, $memory, $seen);
        }

        return $counts;
    }

    /**
     * @param  list<IysConsentChange|InvalidReconciliationRow>  $rows
     * @param  array<string, int>  $counts
     * @param  array<string, array{0: string, 1: CarbonImmutable}>  $memory
     * @param  array<string, true>  $seen
     */
    private function classifyChunk(string $batchId, array $rows, array &$counts, array &$memory, array &$seen): void
    {
        $tenantId = $this->context->requireTenant();
        $insert = [];
        $candidates = [];
        $normalized = [];
        foreach ($rows as $row) {
            if ($row instanceof InvalidReconciliationRow) {
                $insert[$row->rowNumber] = ['outcome' => 'invalid', 'detail' => $row->code];

                continue;
            }
            $type = $row->channel->addressType();
            $address = match ($type) {
                'email' => $this->recipients->email($row->address),
                'phone' => $this->recipients->phone($row->address),
                default => null,
            };
            if ($type === null || $address === null || ! $address['valid']) {
                $insert[$row->rowNumber] = ['outcome' => 'invalid', 'detail' => $type === null ? 'CHANNEL' : 'ADDRESS'];

                continue;
            }
            $hash = hash_hmac('sha256', "{$type}|{$address['address']}", (string) config('platform.audit.hash_key'));
            $base = ['address_hash' => $hash, 'channel' => $row->channel->value, 'recipient_type' => $row->recipientType->value, 'status' => $row->status->value,
                'occurred_at' => $row->occurredAt->utc()->format('Y-m-d H:i:sP'), 'source' => $row->source === null ? null : mb_substr(ReconciliationFile::cell($row->source), 0, 64)];
            if ($address['marketplace']) {
                $insert[$row->rowNumber] = $base + ['outcome' => 'marketplace_excluded'];

                continue;
            }
            $rowKey = hash('sha256', implode('|', [$hash, $row->channel->value, $row->recipientType->value, $row->status->value, $row->occurredAt->utc()->toIso8601String(), $row->transactionId ?? '']));
            if (isset($seen[$rowKey])) {
                // Same change twice in one file: counted, not stored.
                $counts['duplicate']++;

                continue;
            }
            $seen[$rowKey] = true;
            $normalized[$type][$address['address']] = true;
            $candidates[$row->rowNumber] = ['type_addr' => [$type, $address['address']], 'base' => $base + ['row_key' => $rowKey],
                'change' => ['channel' => $row->channel->value, 'type' => $row->recipientType->value, 'status' => $row->status->value, 'at' => $row->occurredAt, 'row_key' => $rowKey]];
        }

        $ids = [];
        foreach ($normalized as $type => $addresses) {
            $ids[$type] = $this->recipients->lookup($type, array_keys($addresses));
        }
        $known = [];
        foreach ($candidates as $n => $c) {
            [$type, $addr] = $c['type_addr'];
            $recipientId = $ids[$type][$addr] ?? null;
            if ($recipientId === null) {
                $insert[$n] = $c['base'] + ['outcome' => 'unknown_recipient'];

                continue;
            }
            $known[$n] = $c['change'] + ['recipient_id' => (string) $recipientId];
            $insert[$n] = $c['base'] + ['recipient_id' => (string) $recipientId];
        }
        $decided = $this->decide($batchId, array_values($known), $memory);
        foreach (array_keys($known) as $i => $n) {
            [$insert[$n]['outcome'], $insert[$n]['detail']] = $decided[$i];
        }

        ksort($insert);
        $values = [];
        foreach ($insert as $n => $r) {
            $counts[$r['outcome']]++;
            $values[] = ['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'batch_id' => $batchId, 'row_number' => $n, 'address_hash' => $r['address_hash'] ?? null,
                'recipient_id' => $r['recipient_id'] ?? null, 'channel' => $r['channel'] ?? null, 'recipient_type' => $r['recipient_type'] ?? null, 'status' => $r['status'] ?? null,
                'occurred_at' => $r['occurred_at'] ?? null, 'source' => $r['source'] ?? null, 'row_key' => $r['row_key'] ?? null, 'outcome' => $r['outcome'], 'detail' => $r['detail'] ?? null];
        }
        foreach (array_chunk($values, 250) as $part) {
            DB::table('iys_consent_changes')->insert($part);
        }
    }

    /**
     * Outcome per change against the newest known official state (stored,
     * or decided earlier in this batch) and local blocks.
     *
     * @param  list<array{recipient_id: string, channel: string, type: string, status: string, at: CarbonImmutable, row_key: string}>  $changes
     * @param  array<string, array{0: string, 1: CarbonImmutable}>  $memory
     * @return list<array{0: string, 1: ?string}>
     */
    private function decide(string $batchId, array $changes, array &$memory): array
    {
        if ($changes === []) {
            return [];
        }
        $tenantId = $this->context->requireTenant();
        $recipientIds = array_values(array_unique(array_column($changes, 'recipient_id')));
        $marks = implode(',', array_fill(0, count($recipientIds), '?'));
        $official = "'".implode("', '", ConsentSourceProvider::OFFICIAL)."'";

        $state = [];
        foreach (DB::select(
            "SELECT DISTINCT ON (recipient_id, channel, recipient_type) recipient_id, channel, recipient_type, status, metadata->>'as_of' AS as_of
             FROM consents WHERE tenant_id = ? AND recipient_id IN ({$marks}) AND source_provider IN ({$official})
             ORDER BY recipient_id, channel, recipient_type, (metadata->>'as_of')::timestamptz DESC NULLS LAST, (status = 'REVOKED') DESC",
            [$tenantId, ...$recipientIds],
        ) as $s) {
            $state["{$s->recipient_id}|{$s->channel}|{$s->recipient_type}"] = [(string) $s->status, CarbonImmutable::parse((string) ($s->as_of ?? '1970-01-01'))];
        }
        $blocked = [];
        foreach (DB::table('suppressions')->where('tenant_id', $tenantId)->whereIn('recipient_id', $recipientIds)->whereNull('lifted_at')->get(['recipient_id', 'channel']) as $s) {
            $blocked[$s->recipient_id][$s->channel ?? '*'] = true;
        }
        $applied = array_flip(DB::table('iys_consent_changes')->where('tenant_id', $tenantId)->where('batch_id', '<>', $batchId)->where('applied', true)
            ->whereIn('row_key', array_column($changes, 'row_key'))->whereIn('outcome', ['onay', 'ret', 'conflict', 'unchanged'])->pluck('row_key')->all());

        $out = [];
        foreach ($changes as $c) {
            $key = "{$c['recipient_id']}|{$c['channel']}|{$c['type']}";
            $current = $state[$key] ?? null;
            if (isset($memory[$key]) && ($current === null || $memory[$key][1]->greaterThanOrEqualTo($current[1]))) {
                $current = $memory[$key];
            }

            if (isset($applied[$c['row_key']])) {
                $out[] = ['duplicate', 'APPLIED_IN_EARLIER_REPORT'];

                continue;
            }
            if ($current !== null && $current[1]->greaterThan($c['at'])) {
                $out[] = $current[0] === $c['status'] ? ['unchanged', null] : ['conflict', 'OLDER_THAN_OFFICIAL'];

                continue;
            }
            if ($current !== null && $current[0] === $c['status']) {
                $out[] = ['unchanged', null];

                continue;
            }
            if ($current !== null && $current[0] === ConsentStatus::Revoked->value && $current[1]->equalTo($c['at'])) {
                // Same instant, different answer: RET wins.
                $out[] = ['conflict', 'SAME_TIME_RET_WINS'];

                continue;
            }
            $memory[$key] = [$c['status'], $c['at']];
            if ($c['status'] === ConsentStatus::Granted->value && (isset($blocked[$c['recipient_id']]['*']) || isset($blocked[$c['recipient_id']][$c['channel']]))) {
                $out[] = ['conflict', 'ONAY_VS_LOCAL_RET'];

                continue;
            }
            $out[] = [$c['status'] === ConsentStatus::Granted->value ? 'onay' : 'ret', null];
        }

        return $out;
    }

    private function record(object $row, string $outcome, ?string $detail, string $batchId): void
    {
        if (! in_array($outcome, ['onay', 'ret', 'conflict'], true)) {
            return;
        }
        $channel = ConsentChannel::from((string) $row->channel);
        $status = ConsentStatus::from((string) $row->status);
        $at = CarbonImmutable::parse((string) $row->occurred_at);
        $this->consents->observe((string) $row->recipient_id, $channel, RecipientType::from((string) $row->recipient_type), ConsentSourceProvider::IysReconciliation, $status,
            $at, 'recon:'.$row->row_key, source: $row->source ?? 'IYS_CHANGE_REPORT', consentDate: $status === ConsentStatus::Granted ? $at : null,
            metadata: ['reconciliation_batch_id' => $batchId], reconciliationBatchId: $batchId);

        if ($outcome === 'conflict') {
            $this->conflicts->open((string) $row->recipient_id, $channel, $detail === 'ONAY_VS_LOCAL_RET' ? ConsentConflicts::OFFICIAL_ONAY_AFTER_LOCAL_RET : ConsentConflicts::OFFICIAL_SOURCES_DISAGREE,
                ['source' => 'IYS_RECONCILIATION', 'batch_id' => $batchId, 'status' => $status->value, 'occurred_at' => $at->toIso8601String(), 'detail' => $detail]);
        }
        if ($status === ConsentStatus::Revoked) {
            // The İYS RET of an earlier unsubscribe is now confirmed by İYS.
            DB::table('consent_write_intents')->where('tenant_id', $this->context->requireTenant())->where('recipient_id', $row->recipient_id)
                ->where('channel', $channel->value)->where('status', 'RET')->whereIn('state', ['pending_write_disabled', 'pending', 'failed'])
                ->update(['state' => 'confirmed', 'confirmed_at' => now(), 'updated_at' => now()]);
        }
    }

    private static function by(Actor $actor): string
    {
        return mb_substr($actor->type.($actor->id !== null ? ':'.$actor->id : ''), 0, 64);
    }
}
