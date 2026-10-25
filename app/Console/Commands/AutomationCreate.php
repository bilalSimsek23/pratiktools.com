<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Automation\AutomationDefinitionError;
use App\Automation\Automations;
use App\Automation\AutomationTemplates;
use App\Console\Commands\Concerns\RunsInTenantContext;
use Illuminate\Console\Command;

/**
 * Creates a draft automation from a template. Drafts do nothing until
 * published, and published automations only plan actions.
 */
final class AutomationCreate extends Command
{
    use RunsInTenantContext;

    protected $signature = 'automation:create {template : abandoned_cart|winback} {--tenant= : Tenant slug} {--name= : Unique name}
        {--delay-minutes=120 : abandoned_cart wait} {--days=90 : winback inactivity days} {--template-version= : e-mail template version id}';

    protected $description = 'Create a draft automation from a template (no sending)';

    public function handle(Automations $automations): int
    {
        return $this->inTenant(function () use ($automations) {
            $definition = AutomationTemplates::make((string) $this->argument('template'), [
                'delay_minutes' => (int) $this->option('delay-minutes'), 'days' => (int) $this->option('days'), 'template_version_id' => $this->option('template-version') ?: null,
            ]);
            try {
                $id = $automations->create((string) ($this->option('name') ?: $this->argument('template')), $definition['trigger'], $definition['steps'], $definition['settings'],
                    Actor::console($this->getName()), (string) $this->argument('template'));
            } catch (AutomationDefinitionError $e) {
                $this->error("[{$e->reason}] {$e->getMessage()}");

                return self::INVALID;
            }
            $this->info("Draft automation {$id} created.");

            return self::SUCCESS;
        });
    }
}
