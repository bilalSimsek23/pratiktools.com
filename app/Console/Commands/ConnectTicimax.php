<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Commerce\TicimaxIntegrations;
use App\Console\Commands\Concerns\RunsInTenantContext;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Input\StreamableInputInterface;

/**
 * Creates or updates a tenant's Ticimax commerce integration. The member
 * code (UyeKodu) is read from STDIN only, so it never appears in shell
 * history, process lists or command arguments, and is stored only in the
 * vault (see TicimaxIntegrations, shared with Super Admin).
 */
final class ConnectTicimax extends Command
{
    use RunsInTenantContext;

    protected $signature = 'ticimax:connect
        {--tenant= : Tenant slug}
        {--service-url= : Web service base URL, e.g. https://www.example.com/Servis/}
        {--storefront-url= : Storefront URL, e.g. https://www.example.com}
        {--secret-stdin : Read the Ticimax member code (UyeKodu) from STDIN}';

    protected $description = 'Connect a tenant to Ticimax (read-only commerce integration)';

    public function handle(TicimaxIntegrations $integrations): int
    {
        $secret = null;
        if ($this->option('secret-stdin')) {
            $stream = $this->input instanceof StreamableInputInterface && $this->input->getStream() !== null ? $this->input->getStream() : STDIN;
            $secret = trim((string) stream_get_contents($stream));
            if ($secret === '') {
                $this->error('No secret on STDIN.');

                return self::INVALID;
            }
        }

        try {
            $integration = $this->inTenant(fn () => $integrations->save((string) $this->option('service-url'), (string) $this->option('storefront-url'), $secret,
                Actor::console($this->getName())));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        } finally {
            unset($secret);
        }

        $this->info("Ticimax integration {$integration->id} saved (status: {$integration->status}). Run commerce:test next.");

        return self::SUCCESS;
    }
}
