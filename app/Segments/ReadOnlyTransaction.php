<?php

namespace App\Segments;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Runs a callback in a READ ONLY transaction with a statement timeout:
 * PostgreSQL itself refuses any write, so previews and dry-runs are
 * provably side-effect free.
 */
final class ReadOnlyTransaction
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(Closure $callback): mixed
    {
        // Nested inside a writing transaction (e.g. a state change that
        // evaluates readiness), READ ONLY would apply to the whole outer
        // transaction: the callback runs as is. Top-level previews and
        // dry-runs always get the READ ONLY guarantee.
        if (DB::transactionLevel() > 0) {
            return $callback();
        }

        return DB::transaction(function () use ($callback) {
            DB::statement('SET TRANSACTION READ ONLY');
            DB::statement('SET LOCAL statement_timeout = '.(int) config('automation.segments.statement_timeout_ms'));

            return $callback();
        });
    }
}
