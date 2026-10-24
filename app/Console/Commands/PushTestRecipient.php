<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Console\Commands\Concerns\RunsInTenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PushTestRecipient extends Command
{
    use RunsInTenantContext;

    protected $signature = 'push:test-recipient {action : add|remove|list} {--tenant= : Tenant slug} {--subscription= : OneSignal subscription id} {--label= : Who this device belongs to} {--approved-by= : Person approving}';

    protected $description = 'Manage the push test allowlist (the only recipients reachable in test_only mode)';

    public function handle(AuditLogger $audit): int
    {
        $actor = Actor::console($this->getName());

        return $this->inTenant(function (string $tenantId) use ($audit, $actor) {
            if ($this->argument('action') === 'list') {
                $this->table(['subscription', 'label', 'approved_by', 'active'], DB::table('test_recipients')->where('channel', 'push')
                    ->get(['provider_recipient_id', 'label', 'approved_by', 'active'])->map(fn ($r) => (array) $r)->all());

                return self::SUCCESS;
            }

            $subscription = strtolower((string) $this->option('subscription'));
            if (! Str::isUuid($subscription)) {
                $this->error('--subscription must be a OneSignal subscription id.');

                return self::INVALID;
            }

            if ($this->argument('action') === 'add') {
                if (trim((string) $this->option('label')) === '' || trim((string) $this->option('approved-by')) === '') {
                    $this->error('--label and --approved-by are required.');

                    return self::INVALID;
                }
                DB::table('test_recipients')->upsert([[
                    'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'channel' => 'push', 'provider' => 'onesignal', 'provider_recipient_id' => $subscription,
                    'label' => mb_substr((string) $this->option('label'), 0, 120), 'approved_by' => mb_substr((string) $this->option('approved-by'), 0, 64),
                    'active' => true, 'deactivated_at' => null, 'created_at' => now(), 'updated_at' => now(),
                ]], ['tenant_id', 'channel', 'provider', 'provider_recipient_id'], ['label', 'approved_by', 'active', 'deactivated_at', 'updated_at']);
                $audit->record(AuditAction::PushTestRecipientAdded, 'test_recipient', $subscription, ['label' => $this->option('label'), 'approved_by' => $this->option('approved-by')], $actor);
                $this->info('Test recipient active.');

                return self::SUCCESS;
            }

            DB::table('test_recipients')->where('channel', 'push')->where('provider', 'onesignal')->where('provider_recipient_id', $subscription)
                ->update(['active' => false, 'deactivated_at' => now(), 'updated_at' => now()]);
            $audit->record(AuditAction::PushTestRecipientRemoved, 'test_recipient', $subscription, [], $actor);
            $this->info('Test recipient deactivated.');

            return self::SUCCESS;
        });
    }
}
