<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Console\Commands\Concerns\FindsEmailIntegration;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Email\SenderIdentities;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sender identities: record the tenant's chosen from address, read its
 * verification state, print the DNS records to publish. Never creates or
 * verifies an identity at the provider and never touches DNS.
 */
final class EmailSender extends Command
{
    use FindsEmailIntegration, RunsInTenantContext;

    protected $signature = 'email:sender {action : add|list|refresh|dns} {--tenant= : Tenant slug} {--provider= : amazon_ses (default: primary)}
        {--from= : From address} {--name= : From display name} {--reply-to= : Reply-To address} {--identity= : Provider identity (domain or address; default: the from domain)}';

    protected $description = 'Manage and check e-mail sender identities (read-only at the provider)';

    public function handle(SenderIdentities $identities): int
    {
        $actor = Actor::console($this->getName());

        return $this->inTenant(function () use ($identities, $actor) {
            $integration = $this->emailIntegration($this->option('provider'));
            $rows = fn () => DB::table('email_sender_identities')->where('integration_id', $integration->id)->orderBy('from_email')->get();

            switch ($this->argument('action')) {
                case 'add':
                    $id = $identities->save($integration, (string) $this->option('from'), $this->option('name'), $this->option('reply-to'), $this->option('identity'), $actor);
                    $this->info("Sender identity {$id} saved (unverified until email:sender refresh).");

                    return self::SUCCESS;
                case 'refresh':
                    foreach ($rows() as $row) {
                        $r = $identities->refresh($integration, $row->id, $actor);
                        $this->line("{$r['from_email']}: verification={$r['verification_status']} dkim={$r['dkim_status']} mail_from={$r['mail_from_status']} spf={$r['spf_status']} dmarc={$r['dmarc_status']}");
                    }

                    return self::SUCCESS;
                case 'dns':
                    foreach ($rows() as $row) {
                        $this->line("{$row->from_email} ({$row->provider_identity_id}):");
                        $this->table(['purpose', 'type', 'name', 'value'], array_map(fn (array $r) => [$r['purpose'], $r['type'], $r['name'], $r['value']], (array) json_decode($row->dns_records, true)));
                    }

                    return self::SUCCESS;
                default:
                    $this->table(['from', 'identity', 'verification', 'dkim', 'mail_from', 'spf', 'dmarc', 'checked'], $rows()->map(fn ($r) => [
                        $r->from_email, $r->provider_identity_id, $r->verification_status, $r->dkim_status, $r->mail_from_status, $r->spf_status, $r->dmarc_status, $r->last_checked_at,
                    ])->all());

                    return self::SUCCESS;
            }
        });
    }
}
