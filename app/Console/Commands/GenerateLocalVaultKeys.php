<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Development helper: prints fresh random keys for the local KEK driver,
 * the fingerprint key and the audit hash key. Never use in production;
 * production KEKs live in a KMS.
 */
final class GenerateLocalVaultKeys extends Command
{
    protected $signature = 'vault:generate-local-keys';

    protected $description = 'Print new local development keys for the secret vault';

    public function handle(): int
    {
        if (app()->environment(['production', 'staging'])) {
            $this->error('Refusing to generate local keys in production or staging.');

            return self::FAILURE;
        }

        foreach (['SECRET_VAULT_LOCAL_KEK', 'SECRET_VAULT_FINGERPRINT_KEY', 'AUDIT_HASH_KEY'] as $name) {
            $this->line($name.'='.base64_encode(random_bytes(32)));
        }

        return self::SUCCESS;
    }
}
