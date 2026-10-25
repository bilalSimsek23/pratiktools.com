<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Leads\LeadError;
use App\Leads\LeadForms;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class LeadFormCommand extends Command
{
    use RunsInTenantContext;

    protected $signature = 'lead-form {action : create|list|activate|deactivate} {--tenant=} {--form=} {--name=} {--placement=footer} {--consent-text=} {--success=}
        {--origin=* : allowed https origins} {--double-opt-in}';

    protected $description = 'Lead forms: create (inactive), list, activate, deactivate';

    public function handle(LeadForms $forms): int
    {
        $actor = Actor::console('lead-form:'.$this->argument('action'));

        return $this->inTenant(function () use ($forms, $actor) {
            try {
                match ($this->argument('action')) {
                    'create' => $this->info('Form '.$forms->create((string) $this->option('name'), (string) $this->option('placement'), (string) $this->option('consent-text'),
                        (string) $this->option('success'), (array) $this->option('origin'), $actor, doubleOptIn: (bool) $this->option('double-opt-in')).' created (inactive).'),
                    'activate', 'deactivate' => $forms->setActive((string) $this->option('form'), $this->argument('action') === 'activate', $actor),
                    default => $this->table(['id', 'public_id', 'name', 'placement', 'active', 'consent v', 'origins'], DB::table('lead_forms')->orderBy('name')->get()
                        ->map(fn ($f) => [$f->id, $f->public_id, $f->name, $f->placement, $f->active ? 'yes' : 'no', $f->consent_version, $f->allowed_origins])->all()),
                };
            } catch (LeadError $e) {
                $this->error("[{$e->reason}] {$e->getMessage()}");

                return self::FAILURE;
            }

            return self::SUCCESS;
        });
    }
}
