<?php

namespace App\Consent\Sync;

use App\Audit\Actor;
use App\Commerce\CommerceSourceFactory;
use App\Commerce\Contracts\ProvidesConsentHistory;
use App\Commerce\Data\ConsentChange;
use App\Consent\ConsentChannel;
use App\Consent\ConsentProviderFactory;
use App\Consent\ConsentSourceProvider;
use App\Consent\ConsentStatus;
use App\Consent\ConsentStore;
use App\Consent\Contracts\ConsentProvider;
use App\Consent\Data\ProviderConsent;
use App\Consent\RecipientDirectory;
use App\Consent\RecipientType;
use App\Integrations\Errors\ProviderError;
use App\Models\IntegrationSyncRun;
use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only consent synchronization (shadow mode). Nothing here writes to
 * İYS, Ticimax or any other provider.
 */
final class ConsentSynchronizer
{
    public const ENTITY_TICIMAX_HISTORY = 'ticimax_consent_history';

    public const ENTITY_IYS_STATUS = 'iys_status_email';

    public const ENTITY_IYS_RESOLVE = 'iys_resolve_email';

    public const ENTITY_IYS_CHANGES = ['citizen' => 'iys_changes_citizen', 'provider' => 'iys_changes_provider'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly RecipientDirectory $recipients,
        private readonly ConsentStore $store,
        private readonly SyncTracker $tracker,
        private readonly CommerceSourceFactory $commerceSources,
        private readonly ConsentProviderFactory $consentProviders,
    ) {}

    /**
     * Recipients from every stored customer (e-mail and phone), customer
     * links, and the store's current permission flags as TICIMAX /
     * TICIMAX_NEWSLETTER observations.
     *
     * @return array{customers: int, email_recipients: int, phone_recipients: int, observations: int}
     */
    public function buildFromCustomers(int $chunk = 2000): array
    {
        $tenantId = $this->context->requireTenant();
        $after = '00000000-0000-0000-0000-000000000000';
        $customers = 0;

        while (true) {
            $rows = DB::table('customers')->where('tenant_id', $tenantId)->where('id', '>', $after)->orderBy('id')->limit($chunk)
                ->get(['id', 'email', 'phone']);
            if ($rows->isEmpty()) {
                break;
            }
            $after = (string) $rows->last()->id;
            $customers += $rows->count();

            DB::transaction(function () use ($rows) {
                $emails = [];
                $phones = [];
                foreach ($rows as $c) {
                    if (($e = $this->recipients->email($c->email)) !== null) {
                        $emails[$c->id] = $e;
                    }
                    if (($p = $this->recipients->phone($c->phone)) !== null) {
                        $phones[$c->id] = $p;
                    }
                }

                $links = [];
                $emailIds = $this->recipients->ensure('email', array_values($emails));
                foreach ($emails as $customerId => $e) {
                    $links[] = ['recipient_id' => $emailIds[$e['address']], 'customer_id' => $customerId, 'link_source' => 'customer_email'];
                }
                $phoneIds = $this->recipients->ensure('phone', array_values($phones));
                foreach ($phones as $customerId => $p) {
                    $links[] = ['recipient_id' => $phoneIds[$p['address']], 'customer_id' => $customerId, 'link_source' => 'customer_phone'];
                }
                $this->recipients->link($links);
            });
        }

        $observations = $this->snapshotStoreConsent();

        return [
            'customers' => $customers,
            'email_recipients' => DB::table('consent_recipients')->where('address_type', 'email')->count(),
            'phone_recipients' => DB::table('consent_recipients')->where('address_type', 'phone')->count(),
            'observations' => $observations,
        ];
    }

    /**
     * Store flags per recipient. An address shared by several members is
     * GRANTED only if every member with a flag says yes; any "no" wins.
     */
    private function snapshotStoreConsent(): int
    {
        $count = 0;
        $specs = [
            [ConsentChannel::Email, ConsentSourceProvider::Ticimax, 'email', "c.source_consent->>'email_opt_in'", "c.source_consent->>'permission_updated_at'"],
            [ConsentChannel::Sms, ConsentSourceProvider::Ticimax, 'phone', "c.source_consent->>'sms_opt_in'", "c.source_consent->>'permission_updated_at'"],
            [ConsentChannel::Email, ConsentSourceProvider::TicimaxNewsletter, 'email', "c.source_consent->'newsletter'->>'email_opt_in'", "c.source_consent->'newsletter'->>'subscribed_at'"],
        ];

        foreach ($specs as [$channel, $provider, $type, $flag, $date]) {
            $rows = DB::cursor(
                "SELECT r.id AS recipient_id, bool_and(({$flag})::boolean) AS all_yes, bool_or(({$flag})::boolean) AS any_yes,
                        count(*) AS members, max(({$date})::timestamptz) AS changed_at
                 FROM consent_recipients r
                 JOIN recipient_customers rc ON rc.recipient_id = r.id AND rc.tenant_id = r.tenant_id
                 JOIN customers c ON c.id = rc.customer_id AND c.tenant_id = rc.tenant_id
                 WHERE r.tenant_id = ? AND r.address_type = ? AND ({$flag}) IS NOT NULL
                 GROUP BY r.id",
                [$this->context->requireTenant(), $type],
            );

            $batch = [];
            foreach ($rows as $row) {
                $batch[] = $row;
                if (count($batch) === 1000) {
                    $count += $this->observeSnapshot($batch, $channel, $provider);
                    $batch = [];
                }
            }
            $count += $this->observeSnapshot($batch, $channel, $provider);
        }

        return $count;
    }

    /**
     * @param  list<object>  $rows
     */
    private function observeSnapshot(array $rows, ConsentChannel $channel, ConsentSourceProvider $provider): int
    {
        if ($rows === []) {
            return 0;
        }

        DB::transaction(function () use ($rows, $channel, $provider) {
            foreach ($rows as $row) {
                $status = $row->all_yes ? ConsentStatus::Granted : ConsentStatus::Revoked;
                $asOf = $row->changed_at !== null ? CarbonImmutable::parse($row->changed_at) : CarbonImmutable::create(2000);
                $this->store->observe(
                    $row->recipient_id, $channel, RecipientType::Individual, $provider, $status, $asOf,
                    eventKey: "snapshot:{$row->recipient_id}:{$channel->value}:{$status->value}:{$asOf->getTimestamp()}",
                    source: $provider === ConsentSourceProvider::TicimaxNewsletter ? 'MailList' : 'UyeIzin',
                    consentDate: $status === ConsentStatus::Granted ? $asOf : null,
                    backfill: true,
                    metadata: array_filter(['members' => (int) $row->members > 1 ? (int) $row->members : null, 'members_disagree' => $row->any_yes && ! $row->all_yes ?: null]),
                );
            }
        });

        return count($rows);
    }

    /**
     * Ticimax permission history in monthly windows (no paging exists).
     */
    public function ticimaxHistory(TenantIntegration $commerce, ?CarbonImmutable $from = null, ?Actor $actor = null, ?float $sliceSeconds = null): IntegrationSyncRun
    {
        $started = microtime(true);
        $cursor = $this->tracker->cursor($commerce, self::ENTITY_TICIMAX_HISTORY);
        $start = $from ?? ($cursor?->last_window_end ? CarbonImmutable::parse($cursor->last_window_end)->subHour() : CarbonImmutable::parse((string) config('consent.ticimax.history_start', '2019-01-01')));
        $end = CarbonImmutable::now()->subMinutes(2);
        $run = $this->tracker->open($commerce, self::ENTITY_TICIMAX_HISTORY, $from === null && $cursor !== null ? 'delta' : 'full', $start, $end);

        try {
            $source = $this->commerceSources->for($commerce, 'consent_history', $actor ?? Actor::job(self::class));
            if (! $source instanceof ProvidesConsentHistory) {
                throw new ProviderError(ProviderError::PERMANENT, 'The commerce source has no consent history.');
            }

            $windowFrom = $run->page_token !== null ? CarbonImmutable::parse($run->page_token) : CarbonImmutable::parse($run->window_start);
            while ($windowFrom->lt($end)) {
                $windowTo = $windowFrom->addMonth()->min($end);
                $changes = $source->consentHistory($windowFrom, $windowTo);
                $stored = $this->observeStoreChanges($changes);
                $this->tracker->progress($run, ['read' => count($changes), 'updated' => $stored, 'skipped' => count($changes) - $stored, 'api_calls' => 1], $windowTo->toIso8601String());
                $windowFrom = $windowTo;

                if ($sliceSeconds !== null && $windowFrom->lt($end) && microtime(true) - $started >= $sliceSeconds) {
                    $run->forceFill(['slices' => $run->slices + 1])->save();

                    return $run;
                }
            }

            return $this->tracker->succeed($run, $commerce);
        } catch (Throwable $e) {
            return $this->tracker->fail($run, $commerce, $e);
        }
    }

    /**
     * @param  list<ConsentChange>  $changes
     */
    private function observeStoreChanges(array $changes): int
    {
        $byType = ['email' => [], 'phone' => []];
        foreach ($changes as $c) {
            $address = $c->channel === ConsentChange::EMAIL ? $this->recipients->email($c->email) : $this->recipients->phone($c->phone);
            if ($address !== null) {
                $byType[$c->channel === ConsentChange::EMAIL ? 'email' : 'phone'][$c->externalId] = $address;
            }
        }
        $ids = [
            'email' => $this->recipients->ensure('email', array_values($byType['email'])),
            'phone' => $this->recipients->ensure('phone', array_values($byType['phone'])),
        ];

        $changed = 0;
        DB::transaction(function () use ($changes, $byType, $ids, &$changed) {
            foreach ($changes as $c) {
                $type = $c->channel === ConsentChange::EMAIL ? 'email' : 'phone';
                $address = $byType[$type][$c->externalId] ?? null;
                if ($address === null) {
                    continue;
                }
                $status = $c->granted ? ConsentStatus::Granted : ConsentStatus::Revoked;
                $changed += (int) $this->store->observe(
                    $ids[$type][$address['address']],
                    $c->channel === ConsentChange::EMAIL ? ConsentChannel::Email : ConsentChannel::Sms,
                    RecipientType::Individual,
                    ConsentSourceProvider::Ticimax,
                    $status,
                    $c->occurredAt,
                    eventKey: "history:{$c->externalId}",
                    source: $c->source,
                    consentDate: $c->granted ? $c->occurredAt : null,
                    externalId: $c->externalId,
                    backfill: $c->occurredAt->lt(CarbonImmutable::now()->subDay()),
                    metadata: ['explicit_change' => true],
                );
            }
        });

        return $changed;
    }

    /**
     * Bulk İYS status of every valid, non-marketplace e-mail recipient.
     * İYS returns only the ONAY addresses; the rest become UNKNOWN (revoked
     * or no record) unless already known to be REVOKED.
     */
    public function iysStatus(TenantIntegration $iys, ?float $sliceSeconds = null, ?Actor $actor = null): IntegrationSyncRun
    {
        $run = $this->tracker->open($iys, self::ENTITY_IYS_STATUS, 'full');
        $started = microtime(true);

        try {
            $provider = $this->consentProviders->for($iys, 'consent_status_sync', $actor ?? Actor::job(self::class));
            $types = array_map(fn ($t) => RecipientType::from($t), config('consent.iys.recipient_types'));
            [$typeIndex, $after] = $this->statusToken($run->page_token);

            for (; $typeIndex < count($types); $typeIndex++, $after = '00000000-0000-0000-0000-000000000000') {
                $type = $types[$typeIndex];
                while (true) {
                    $batch = DB::table('consent_recipients')
                        ->where('address_type', 'email')->where('is_valid', true)->where('is_marketplace', false)
                        ->where('id', '>', $after)->orderBy('id')->limit($provider->bulkBatchSize())
                        ->pluck('address', 'id')->all();
                    if ($batch === []) {
                        break;
                    }

                    $granted = array_flip($provider->bulkQueryGranted(array_values($batch), ConsentChannel::Email, $type));
                    [$changed, $same] = $this->observeBulk($batch, $granted, $type, $run->id);
                    $after = (string) array_key_last($batch);
                    $this->tracker->progress($run, ['read' => count($batch), 'updated' => $changed, 'skipped' => $same, 'api_calls' => 1], "{$typeIndex}:{$after}");

                    if ($sliceSeconds !== null && microtime(true) - $started >= $sliceSeconds) {
                        $run->forceFill(['slices' => $run->slices + 1])->save();

                        return $run;
                    }
                }
            }

            return $this->tracker->succeed($run, $iys, full: true);
        } catch (Throwable $e) {
            return $this->tracker->fail($run, $iys, $e);
        }
    }

    /**
     * @param  array<string, string>  $batch  recipient id => address
     * @param  array<string, int>  $granted  address => index
     * @return array{0: int, 1: int}
     */
    private function observeBulk(array $batch, array $granted, RecipientType $type, string $runId): array
    {
        $known = DB::table('consents')->whereIn('recipient_id', array_keys($batch))
            ->where('channel', 'EMAIL')->where('recipient_type', $type->value)->where('source_provider', 'IYS')
            ->pluck('status', 'recipient_id')->all();
        $changed = 0;

        DB::transaction(function () use ($batch, $granted, $type, $runId, $known, &$changed) {
            $now = CarbonImmutable::now();
            foreach ($batch as $recipientId => $address) {
                if (isset($granted[$address])) {
                    $changed += (int) $this->store->observe($recipientId, ConsentChannel::Email, $type, ConsentSourceProvider::Iys, ConsentStatus::Granted, $now,
                        eventKey: "bulk:{$runId}:{$recipientId}:{$type->value}", metadata: ['method' => 'bulk', 'not_granted' => null, 'resolved' => null]);
                } elseif (($known[$recipientId] ?? null) === ConsentStatus::Revoked->value) {
                    // Bulk cannot tell RET from "no record": keep the known RET.
                    $this->store->touch($recipientId, ConsentChannel::Email, $type, ConsentSourceProvider::Iys, ['method' => 'bulk', 'not_granted' => true]);
                } else {
                    $changed += (int) $this->store->observe($recipientId, ConsentChannel::Email, $type, ConsentSourceProvider::Iys, ConsentStatus::Unknown, $now,
                        eventKey: "bulk:{$runId}:{$recipientId}:{$type->value}", metadata: ['method' => 'bulk', 'not_granted' => true]);
                }
            }
        });

        return [$changed, count($batch) - $changed];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function statusToken(?string $token): array
    {
        if ($token === null || ! str_contains($token, ':')) {
            return [0, '00000000-0000-0000-0000-000000000000'];
        }
        [$index, $after] = explode(':', $token, 2);

        return [(int) $index, $after];
    }

    /**
     * Single status queries for addresses bulk could not classify, within a
     * budget (1,000 per hour per IP, shared by all tenants). Addresses the
     * store marks as consenting and newsletter subscribers go first.
     */
    public function iysResolve(TenantIntegration $iys, ?int $budget = null, ?Actor $actor = null, ?float $sliceSeconds = null): IntegrationSyncRun
    {
        $budget ??= (int) config('consent.iys.resolve_budget_per_run');
        $run = $this->tracker->open($iys, self::ENTITY_IYS_RESOLVE);
        $started = microtime(true);
        // A sliced run continues where it stopped; the budget covers the run.
        $budget = max(0, $budget - $run->records_read);

        try {
            $provider = $this->consentProviders->for($iys, 'consent_status_resolve', $actor ?? Actor::job(self::class));
            $candidates = DB::select(
                "SELECT r.id, r.address,
                        (SELECT max(CASE WHEN t.status = 'GRANTED' THEN 2 WHEN t.source_provider = 'TICIMAX_NEWSLETTER' THEN 1 ELSE 0 END)
                           FROM consents t WHERE t.recipient_id = r.id AND t.tenant_id = r.tenant_id AND t.channel = 'EMAIL' AND t.source_provider <> 'IYS') AS priority
                 FROM consents i JOIN consent_recipients r ON r.id = i.recipient_id AND r.tenant_id = i.tenant_id
                 WHERE i.tenant_id = ? AND i.source_provider = 'IYS' AND i.channel = 'EMAIL' AND i.recipient_type = 'INDIVIDUAL'
                   AND i.status = 'UNKNOWN' AND i.metadata->>'resolved' IS NULL
                 ORDER BY priority DESC NULLS LAST, r.id
                 LIMIT ?",
                [$this->context->requireTenant(), $budget],
            );

            foreach ($candidates as $c) {
                if ($sliceSeconds !== null && microtime(true) - $started >= $sliceSeconds) {
                    $run->forceFill(['slices' => $run->slices + 1])->save();

                    return $run;
                }

                try {
                    $result = $provider->queryConsent($c->address, ConsentChannel::Email, RecipientType::Individual);
                } catch (ProviderError $e) {
                    if ($e->category === ProviderError::RATE_LIMITED) {
                        break;
                    }
                    throw $e;
                }

                if ($result === null) {
                    $this->store->touch($c->id, ConsentChannel::Email, RecipientType::Individual, ConsentSourceProvider::Iys, ['resolved' => 'no_record']);
                    $this->tracker->progress($run, ['read' => 1, 'skipped' => 1, 'api_calls' => 1], $c->id);

                    continue;
                }

                $this->observeIys($result, 'single', ['resolved' => 'single_status']);
                $this->tracker->progress($run, ['read' => 1, 'updated' => 1, 'api_calls' => 1], $c->id);
            }

            return $this->tracker->succeed($run, $iys);
        } catch (Throwable $e) {
            return $this->tracker->fail($run, $iys, $e);
        }
    }

    /**
     * Consent movements since the stored "after" cursor. İYS only keeps the
     * last 7 days (minus 1 hour): a cursor older than that cannot be
     * continued, so the gap is recorded and a full status sync is needed.
     *
     * @param  'citizen'|'provider'  $origin
     */
    public function iysChanges(TenantIntegration $iys, string $origin, ?Actor $actor = null): IntegrationSyncRun
    {
        $entity = self::ENTITY_IYS_CHANGES[$origin];
        $cursor = $this->tracker->cursor($iys, $entity);
        $run = $this->tracker->open($iys, $entity);

        try {
            $after = $run->page_token ?? $cursor?->provider_cursor;
            $lastSuccess = $cursor?->last_success_at ? CarbonImmutable::parse($cursor->last_success_at) : null;
            if ($after !== null && $lastSuccess !== null && $lastSuccess->lt(CarbonImmutable::now()->subHours((int) config('consent.iys.changes_window_hours')))) {
                $this->tracker->note($iys, $run, ProviderError::PERMANENT, 'consent_changes',
                    'İYS changes cursor is older than the 7-day window; movements may be missing. Run a full status sync.', ['origin' => $origin]);
                $after = null;
            }

            $provider = $this->consentProviders->for($iys, 'consent_changes', $actor ?? Actor::job(self::class));
            while (true) {
                $page = $provider->getChanges($after, $origin);
                $changed = 0;
                DB::transaction(function () use ($page, $origin, &$changed) {
                    foreach ($page->changes as $change) {
                        $changed += (int) $this->observeIys($change, 'changes', ['origin' => $origin]);
                    }
                });
                $this->tracker->progress($run, ['read' => count($page->changes), 'updated' => $changed, 'skipped' => count($page->changes) - $changed, 'api_calls' => $page->apiCalls], $page->after);

                if ($page->changes === [] || $page->after === null || $page->after === $after) {
                    $after = $page->after ?? $after;
                    break;
                }
                $after = $page->after;
            }

            return $this->tracker->succeed($run, $iys, $after);
        } catch (Throwable $e) {
            return $this->tracker->fail($run, $iys, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function observeIys(ProviderConsent $c, string $method, array $metadata = []): bool
    {
        $type = $c->channel->addressType();
        $address = $type === 'email' ? $this->recipients->email($c->recipient) : $this->recipients->phone($c->recipient);
        if ($type === null || $address === null) {
            return false;
        }
        $recipientId = $this->recipients->ensure($type, [$address])[$address['address']];
        $asOf = $c->creationDate ?? CarbonImmutable::now();

        return $this->store->observe(
            $recipientId, $c->channel, $c->recipientType, ConsentSourceProvider::Iys, $c->status, $method === 'single' ? CarbonImmutable::now() : $asOf,
            eventKey: $c->transactionId !== null ? "tx:{$c->transactionId}" : "{$method}:{$recipientId}:{$c->channel->value}:{$c->recipientType->value}:{$c->status->value}:".($c->consentDate?->getTimestamp() ?? 0),
            source: $c->source,
            consentDate: $c->consentDate,
            externalId: $c->transactionId,
            backfill: $asOf->lt(CarbonImmutable::now()->subDay()),
            metadata: ['method' => $method] + $metadata,
        );
    }

    public function consentProvider(TenantIntegration $iys, string $purpose): ConsentProvider
    {
        return $this->consentProviders->for($iys, $purpose);
    }
}
