<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Site\SiteStorageCheck;
use Illuminate\Console\Command;

/**
 * Write/exists/read/delete round trip on the website media disk. Prints
 * step results only (no configuration or credentials). Exit 1 on failure.
 */
final class SiteStorageCheckCommand extends Command
{
    protected $signature = 'site:storage-check {--disk= : Disk name (default: the website media disk)}';

    protected $description = 'Round-trip test of the website media storage (no secrets printed)';

    public function handle(SiteStorageCheck $check): int
    {
        $r = $check->run(Actor::console($this->getName()), $this->option('disk') ?: null);
        $this->table(['check', 'result'], [['disk', $r['disk']], ['driver', $r['driver'] ?? '—'], ['persistent', $r['persistent'] ? 'yes' : 'no'],
            ...array_map(fn ($k, $v) => [$k, $v ? 'PASS' : 'FAIL'], array_keys($r['steps']), $r['steps']), ['error', $r['error'] ?? '—']]);
        $r['passed'] ? $this->info('Storage check PASS.') : $this->error('Storage check FAIL.');

        return $r['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
