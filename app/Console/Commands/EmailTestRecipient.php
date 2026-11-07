<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Email\InvalidEmailMessage;
use App\Email\TestRecipients;
use Illuminate\Console\Command;

/**
 * The e-mail test allowlist: the only addresses reachable in test_only
 * mode and the only ones a "test" message can ever go to. The same
 * TestRecipients service as Super Admin › Entegrasyonlar › Amazon SES.
 */
final class EmailTestRecipient extends Command
{
    use RunsInTenantContext;

    protected $signature = 'email:test-recipient {action : add|remove|list} {--tenant= : Tenant slug} {--email= : Address} {--label= : Whose address} {--approved-by= : Person approving}';

    protected $description = 'Manage the e-mail test allowlist';

    public function handle(TestRecipients $recipients): int
    {
        $actor = Actor::console($this->getName());

        return $this->inTenant(function () use ($recipients, $actor) {
            if ($this->argument('action') === 'list') {
                $this->table(['email', 'label', 'approved_by', 'active'], $recipients->list()
                    ->map(fn ($r) => [$r->email, $r->label, $r->approved_by, $r->active])->all());

                return self::SUCCESS;
            }

            try {
                if ($this->argument('action') === 'add') {
                    $recipients->add((string) $this->option('email'), (string) $this->option('label'), (string) $this->option('approved-by'), $actor);
                    $this->info('Test recipient active.');
                } else {
                    $recipients->remove((string) $this->option('email'), $actor);
                    $this->info('Test recipient deactivated.');
                }
            } catch (InvalidEmailMessage $e) {
                $this->error(match ($e->reason) {
                    'INVALID_RECIPIENT' => '--email must be a single valid address.',
                    default => '--label and --approved-by are required.',
                });

                return self::INVALID;
            }

            return self::SUCCESS;
        });
    }
}
