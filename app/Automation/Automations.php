<?php

namespace App\Automation;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Automation lifecycle. A definition change is always a new version; a
 * published version is never edited (database trigger). Publishing a new
 * version supersedes the old one, while runs already started keep the
 * version they started with. Archiving cancels active runs. Every change
 * is audited.
 */
final class Automations
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AutomationDefinition $definitions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $trigger
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>  $settings
     */
    public function create(string $name, array $trigger, array $steps, array $settings, Actor $actor, ?string $template = null): string
    {
        if (trim($name) === '' || mb_strlen($name) > 120) {
            throw new AutomationDefinitionError('INVALID_NAME', 'A name of up to 120 characters is required.');
        }
        $this->definitions->validate($trigger, $steps, $settings);
        $id = (string) Str::uuid7();

        DB::transaction(function () use ($id, $name, $trigger, $steps, $settings, $actor, $template) {
            DB::table('automations')->insert(['id' => $id, 'tenant_id' => $this->context->requireTenant(), 'name' => trim($name), 'template' => $template,
                'status' => AutomationStatus::Draft->value, 'created_by' => self::by($actor), 'created_at' => now(), 'updated_at' => now()]);
            $this->insertVersion($id, 1, $trigger, $steps, $settings, $actor);
            $this->audit->record(AuditAction::AutomationCreated, 'automation', $id, ['template' => $template, 'trigger' => $trigger['type']], $actor);
        });

        return $id;
    }

    /**
     * New draft version (the published one stays as it is until publish()).
     *
     * @param  array<string, mixed>  $trigger
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>  $settings
     */
    public function change(string $automationId, array $trigger, array $steps, array $settings, Actor $actor): string
    {
        $this->definitions->validate($trigger, $steps, $settings);

        return DB::transaction(function () use ($automationId, $trigger, $steps, $settings, $actor) {
            $automation = $this->lock($automationId);
            if ($automation->status === AutomationStatus::Archived->value) {
                throw new AutomationDefinitionError('ARCHIVED', 'An archived automation cannot change.');
            }
            $next = (int) DB::table('automation_versions')->where('tenant_id', $automation->tenant_id)->where('automation_id', $automationId)->max('version') + 1;
            $id = $this->insertVersion($automationId, $next, $trigger, $steps, $settings, $actor);
            DB::table('automations')->where('tenant_id', $automation->tenant_id)->where('id', $automationId)->update(['updated_at' => now()]);
            $this->audit->record(AuditAction::AutomationChanged, 'automation', $automationId, ['version' => $next], $actor);

            return $id;
        });
    }

    /**
     * Publish the latest version (or resume a paused automation when the
     * latest version is already the published one).
     */
    public function publish(string $automationId, Actor $actor): void
    {
        DB::transaction(function () use ($automationId, $actor) {
            $automation = $this->lock($automationId);
            $this->transition($automation, AutomationStatus::Published);
            $latest = DB::table('automation_versions')->where('tenant_id', $automation->tenant_id)->where('automation_id', $automationId)->orderByDesc('version')->first();

            if ($latest->published_at === null) {
                DB::table('automation_versions')->where('tenant_id', $automation->tenant_id)->where('automation_id', $automationId)->where('status', 'published')
                    ->update(['status' => 'superseded']);
                DB::table('automation_versions')->where('tenant_id', $automation->tenant_id)->where('id', $latest->id)->update(['status' => 'published', 'published_at' => now()]);
            }
            $resumed = $automation->status === AutomationStatus::Paused->value && $latest->published_at !== null;
            DB::table('automations')->where('tenant_id', $automation->tenant_id)->where('id', $automationId)
                ->update(['status' => AutomationStatus::Published->value, 'published_version_id' => $latest->id, 'updated_at' => now()]);
            $this->audit->record($resumed ? AuditAction::AutomationResumed : AuditAction::AutomationPublished, 'automation', $automationId, ['version' => $latest->version], $actor);
        });
    }

    /**
     * Paused: no new runs, waiting runs are held (not advanced) until published again.
     */
    public function pause(string $automationId, Actor $actor): void
    {
        DB::transaction(function () use ($automationId, $actor) {
            $automation = $this->lock($automationId);
            $this->transition($automation, AutomationStatus::Paused);
            DB::table('automations')->where('tenant_id', $automation->tenant_id)->where('id', $automationId)->update(['status' => AutomationStatus::Paused->value, 'updated_at' => now()]);
            $this->audit->record(AuditAction::AutomationPaused, 'automation', $automationId, [], $actor);
        });
    }

    public function archive(string $automationId, Actor $actor): int
    {
        return DB::transaction(function () use ($automationId, $actor) {
            $automation = $this->lock($automationId);
            $this->transition($automation, AutomationStatus::Archived);
            DB::table('automations')->where('tenant_id', $automation->tenant_id)->where('id', $automationId)->update(['status' => AutomationStatus::Archived->value, 'updated_at' => now()]);
            $cancelled = DB::table('automation_runs')->where('tenant_id', $automation->tenant_id)->where('automation_id', $automationId)
                ->whereIn('status', ['pending', 'waiting', 'running'])
                ->update(['status' => 'cancelled', 'exit_reason' => 'automation_archived', 'completed_at' => now(), 'claim_token' => null, 'updated_at' => now()]);
            $this->audit->record(AuditAction::AutomationArchived, 'automation', $automationId, ['cancelled_runs' => $cancelled], $actor);

            return $cancelled;
        });
    }

    public function cancelRun(string $runId, string $reason, Actor $actor): bool
    {
        $updated = DB::table('automation_runs')->where('tenant_id', $this->context->requireTenant())->where('id', $runId)->whereIn('status', ['pending', 'waiting', 'running'])
            ->update(['status' => 'cancelled', 'exit_reason' => 'manual_cancel', 'completed_at' => now(), 'claim_token' => null, 'updated_at' => now()]);
        if ($updated === 1) {
            $this->audit->record(AuditAction::AutomationRunCancelled, 'automation_run', $runId, ['reason' => mb_substr($reason, 0, 120)], $actor);
        }

        return $updated === 1;
    }

    /**
     * @param  array<string, mixed>  $trigger
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>  $settings
     */
    private function insertVersion(string $automationId, int $version, array $trigger, array $steps, array $settings, Actor $actor): string
    {
        $id = (string) Str::uuid7();
        $t = json_encode($trigger, JSON_THROW_ON_ERROR);
        $s = json_encode($steps, JSON_THROW_ON_ERROR);
        $o = json_encode((object) $settings, JSON_THROW_ON_ERROR);
        DB::table('automation_versions')->insert(['id' => $id, 'tenant_id' => $this->context->requireTenant(), 'automation_id' => $automationId, 'version' => $version,
            'trigger' => $t, 'steps' => $s, 'settings' => $o, 'definition_hash' => hash('sha256', $t.'|'.$s.'|'.$o), 'status' => 'draft', 'created_by' => self::by($actor), 'created_at' => now()]);

        return $id;
    }

    private function lock(string $automationId): object
    {
        return DB::table('automations')->where('tenant_id', $this->context->requireTenant())->where('id', $automationId)->lockForUpdate()->first()
            ?? throw new AutomationDefinitionError('NOT_FOUND', 'Unknown automation.');
    }

    private function transition(object $automation, AutomationStatus $to): void
    {
        if (! AutomationStatus::from($automation->status)->canBecome($to)) {
            throw new AutomationDefinitionError('INVALID_TRANSITION', "Cannot go from {$automation->status} to {$to->value}.");
        }
    }

    private static function by(Actor $actor): string
    {
        return mb_substr($actor->type.($actor->id !== null ? ':'.$actor->id : ''), 0, 64);
    }
}
