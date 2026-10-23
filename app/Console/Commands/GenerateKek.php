<?php

namespace App\Console\Commands;

use App\Security\Vault\CloudSecretKeyEncryptionService;
use App\Security\Vault\KekPolicy;
use App\Security\Vault\SymmetricKeyring;
use Illuminate\Console\Command;

/**
 * Operator tool, run on a trusted workstation: prints a new platform KEK
 * and its derived id once. Nothing is logged, stored or written to a file.
 *
 * Refused in production, staging and on Laravel Cloud: command output there
 * may be retained by the platform, and the runtime must never mint keys.
 */
final class GenerateKek extends Command
{
    protected $signature = 'vault:generate-kek';

    protected $description = 'Print a new platform KEK (cloud_secret) and its kek_id, once';

    public function handle(): int
    {
        if (app()->environment(['production', 'staging']) || laravel_cloud()) {
            $this->error('Refusing: generate the KEK on a trusted workstation, not in a deployed environment.');

            return self::FAILURE;
        }

        do {
            $raw = random_bytes(32);
        } while (KekPolicy::lowEntropy($raw));

        $this->line('SECRET_VAULT_KEK='.base64_encode($raw));
        $this->line('kek_id='.SymmetricKeyring::deriveId(CloudSecretKeyEncryptionService::PREFIX, $raw));
        sodium_memzero($raw);

        $this->newLine();
        $this->warn('Shown once. Store it in the offline escrow and as a Laravel Cloud Secret, nowhere else.');
        $this->warn('Clear your terminal scrollback afterwards.');

        return self::SUCCESS;
    }
}
