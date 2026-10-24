<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Email\InvalidEmailMessage;
use App\Email\MessageRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The e-mail test allowlist: the only addresses reachable in test_only
 * mode and the only ones a "test" message can ever go to.
 */
final class EmailTestRecipient extends Command
{
    use RunsInTenantContext;

    protected $signature = 'email:test-recipient {action : add|remove|list} {--tenant= : Tenant slug} {--email= : Address} {--label= : Whose address} {--approved-by= : Person approving}';

    protected $description = 'Manage the e-mail test allowlist';

    public function handle(AuditLogger $audit): int
    {
        $actor = Actor::console($this->getName());

        return $this->inTenant(function (string $tenantId) use ($audit, $actor) {
            if ($this->argument('action') === 'list') {
                $this->table(['email', 'label', 'approved_by', 'active'], DB::table('test_recipients')->where('channel', 'email')
                    ->get(['provider_recipient_id', 'label', 'approved_by', 'active'])->map(fn ($r) => (array) $r)->all());

                return self::SUCCESS;
            }

            try {
                $email = MessageRules::address((string) $this->option('email'), 'INVALID_RECIPIENT');
            } catch (InvalidEmailMessage) {
                $this->error('--email must be a single valid address.');

                return self::INVALID;
            }
            $ref = substr(hash('sha256', $email), 0, 16);

            if ($this->argument('action') === 'add') {
                if (trim((string) $this->option('label')) === '' || trim((string) $this->option('approved-by')) === '') {
                    $this->error('--label and --approved-by are required.');

                    return self::INVALID;
                }
                DB::table('test_recipients')->upsert([[
                    'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'channel' => 'email', 'provider' => '*', 'provider_recipient_id' => $email,
                    'label' => mb_substr((string) $this->option('label'), 0, 120), 'approved_by' => mb_substr((string) $this->option('approved-by'), 0, 64),
                    'active' => true, 'deactivated_at' => null, 'created_at' => now(), 'updated_at' => now(),
                ]], ['tenant_id', 'channel', 'provider', 'provider_recipient_id'], ['label', 'approved_by', 'active', 'deactivated_at', 'updated_at']);
                $audit->record(AuditAction::EmailTestRecipientAdded, 'test_recipient', $ref, ['label' => $this->option('label'), 'approved_by' => $this->option('approved-by')], $actor);
                $this->info('Test recipient active.');

                return self::SUCCESS;
            }

            DB::table('test_recipients')->where('channel', 'email')->where('provider', '*')->where('provider_recipient_id', $email)
                ->update(['active' => false, 'deactivated_at' => now(), 'updated_at' => now()]);
            $audit->record(AuditAction::EmailTestRecipientRemoved, 'test_recipient', $ref, [], $actor);
            $this->info('Test recipient deactivated.');

            return self::SUCCESS;
        });
    }
}
