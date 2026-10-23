<?php

namespace App\Console\Commands;

use App\Security\Vault\KekMaintenance;
use Illuminate\Console\Command;
use Throwable;

/**
 * Deploy gate, run before migrations: the configured KEKs open every data
 * key in use. Exit code 1 stops the deploy, so a wrong or missing KEK never
 * goes live. Prints KEK ids (derived fingerprints) and results only.
 */
final class VerifyKek extends Command
{
    protected $signature = 'vault:verify-kek';

    protected $description = 'Verify that the configured KEKs open every data key in use (deploy gate)';

    public function handle(): int
    {
        try {
            $result = app(KekMaintenance::class)->verify(app()->isProduction());
        } catch (Throwable $e) {
            $this->error('KEK verification could not run ('.class_basename($e).'). Check SECRET_VAULT_* settings.');

            return self::FAILURE;
        }

        $this->table(['check', 'kek_id', 'result'], array_map(fn ($r) => [$r['check'], $r['kek_id'] ?? '-', $r['result']], $result['rows']));

        if (! $result['ok']) {
            $this->error('KEK verification FAILED: do not deploy. See docs/production/secret-vault-rotation.md.');

            return self::FAILURE;
        }

        $this->info('KEK verification passed.');

        return self::SUCCESS;
    }
}
