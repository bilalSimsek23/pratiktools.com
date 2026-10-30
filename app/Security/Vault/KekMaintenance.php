<?php

namespace App\Security\Vault;

use App\Audit\Actor;
use App\Security\Vault\Contracts\KeyEncryptionService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Platform-wide KEK operations over every tenant: inventory, deploy-time
 * verification and re-wrapping. Works with KEK ids and counts only; data
 * keys are unwrapped in memory to prove or move them and never returned.
 */
final class KekMaintenance
{
    public const OK = 'ok';

    public const BOOTSTRAP = 'bootstrap_no_data_keys';

    public const CURRENT_KEY_FAILED = 'current_key_failed';

    public const UNKNOWN_KEK = 'unknown_kek_in_use';

    public const WRONG_KEK = 'kek_does_not_open_data_key';

    public const LOCAL_IN_PRODUCTION = 'local_kek_in_production';

    public const INVENTORY_UNAVAILABLE = 'inventory_unavailable';

    /**
     * Which step verify() reached, for a failure diagnostic (no data).
     */
    public string $stage = 'not_started';

    public function __construct(
        private readonly KeyEncryptionService $kms,
        private readonly SecretVault $vault,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<array{kek_id: string, data_keys: int, active_keys: int, tenants: int}>|null null before the schema exists
     */
    public function inventory(): ?array
    {
        if (! $this->inventoryAvailable()) {
            return null;
        }

        return array_map(fn ($r) => [
            'kek_id' => (string) $r->kek_id,
            'data_keys' => (int) $r->data_keys,
            'active_keys' => (int) $r->active_keys,
            'tenants' => (int) $r->tenants,
        ], DB::select('SELECT * FROM app_kek_inventory()'));
    }

    /**
     * Wrap and unwrap a throwaway key with the current KEK.
     */
    public function currentKeyWorks(): bool
    {
        try {
            $probe = random_bytes(32);
            $ok = hash_equals($probe, $this->kms->unwrap($this->kms->wrap($probe, 'kek-self-test'), $this->kms->currentKeyId(), 'kek-self-test'));
            sodium_memzero($probe);

            return $ok;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Cheap check for /health: current key works and every KEK id in use is
     * known (no unwrap of real data keys).
     *
     * @return array{status: string, reason: string}
     */
    public function quickStatus(bool $production): array
    {
        if (! $this->currentKeyWorks()) {
            return ['status' => 'fail', 'reason' => self::CURRENT_KEY_FAILED];
        }

        try {
            $inventory = $this->inventory();
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'fail', 'reason' => self::INVENTORY_UNAVAILABLE];
        }

        foreach ($inventory ?? [] as $row) {
            if ($production && str_starts_with($row['kek_id'], LocalKeyEncryptionService::PREFIX)) {
                return ['status' => 'fail', 'reason' => self::LOCAL_IN_PRODUCTION];
            }
            if (! $this->kms->knows($row['kek_id'])) {
                return ['status' => 'fail', 'reason' => self::UNKNOWN_KEK];
            }
        }

        return ['status' => 'ok', 'reason' => $inventory === null || $inventory === [] ? self::BOOTSTRAP : self::OK];
    }

    /**
     * Deploy gate: every KEK id in use is configured, one data key per id
     * really unwraps, no local ids in production, the current key works.
     *
     * @return array{ok: bool, rows: list<array{check: string, kek_id: ?string, result: string}>}
     */
    public function verify(bool $production): array
    {
        $rows = [];
        $add = function (string $check, ?string $kekId, string $result) use (&$rows) {
            $rows[] = ['check' => $check, 'kek_id' => $kekId, 'result' => $result];
        };

        $this->stage = 'current_kek_self_test';
        $add('current KEK wraps and unwraps', $this->kms->currentKeyId(), $this->currentKeyWorks() ? self::OK : self::CURRENT_KEY_FAILED);

        $this->stage = 'data_key_inventory';
        $inventory = $this->inventory();
        if ($inventory === null || $inventory === []) {
            $add('data keys in use', null, self::BOOTSTRAP);

            return ['ok' => collect($rows)->every(fn ($r) => in_array($r['result'], [self::OK, self::BOOTSTRAP], true)), 'rows' => $rows];
        }

        $this->stage = 'data_key_samples';
        $samples = collect(DB::select('SELECT * FROM app_kek_samples()'))->keyBy('kek_id');
        $this->stage = 'sample_data_key_unwrap';
        foreach ($inventory as $row) {
            $id = $row['kek_id'];
            if ($production && str_starts_with($id, LocalKeyEncryptionService::PREFIX)) {
                $add('no local KEK in production', $id, self::LOCAL_IN_PRODUCTION);

                continue;
            }
            if (! $this->kms->knows($id)) {
                $add("KEK configured ({$row['data_keys']} data keys)", $id, self::UNKNOWN_KEK);

                continue;
            }

            $sample = $samples[$id] ?? null;
            try {
                $opens = $sample !== null && $this->context->run((string) $sample->tenant_id, fn () => $this->vault->canUnwrap((int) $sample->version));
            } catch (Throwable) {
                $opens = false;
            }
            $add("sample data key unwraps ({$row['data_keys']} data keys, {$row['tenants']} tenants)", $id, $opens ? self::OK : self::WRONG_KEK);
        }

        $this->stage = 'done';

        return ['ok' => collect($rows)->every(fn ($r) => $r['result'] === self::OK), 'rows' => $rows];
    }

    /**
     * Re-wrap, tenant by tenant (one transaction each), every data key not
     * under the current KEK. Safe to interrupt and run again: finished
     * tenants have nothing left to do.
     *
     * @param  (callable(string, int): void)|null  $progress  tenant id, keys re-wrapped
     * @return array{tenants: int, keys: int}
     */
    public function rewrapAll(Actor $actor, ?callable $progress = null): array
    {
        $tenants = 0;
        $keys = 0;

        foreach (DB::select('SELECT tenant_id FROM app_kek_stale_tenants(?)', [$this->kms->currentKeyId()]) as $row) {
            $count = $this->context->run((string) $row->tenant_id, fn () => $this->vault->rewrapDataKeys($actor));
            $tenants++;
            $keys += $count;
            if ($progress !== null) {
                $progress((string) $row->tenant_id, $count);
            }
        }

        return ['tenants' => $tenants, 'keys' => $keys];
    }

    private function inventoryAvailable(): bool
    {
        $this->stage = 'data_key_table_check';
        $table = Schema::hasTable('tenant_data_keys');
        $this->stage = 'data_key_inventory_function_check';
        $present = $table && DB::selectOne("SELECT to_regprocedure('app_kek_inventory()') IS NOT NULL AS present")->present;
        $this->stage = 'data_key_inventory';

        return $present;
    }
}
