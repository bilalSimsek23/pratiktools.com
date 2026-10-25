<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Models\TenantUser;
use App\Models\User;
use App\Tenancy\TenantRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Operator CLI for panel members of one tenant. Passwords come from a
 * hidden prompt or STDIN, never from arguments (shell history, logs).
 * Every change is audited; role changes are the "permission change" event.
 */
final class TenantMember extends Command
{
    use RunsInTenantContext;

    protected $signature = 'tenant:member {action : list|add|role|disable|enable} {--tenant= : Tenant slug} {--email= : Member e-mail}
        {--name= : Display name (add)} {--role= : owner|admin|marketer|approver|viewer} {--password-stdin : Read the password from STDIN (add)}';

    protected $description = 'List, add or change panel members of a tenant';

    public function handle(AuditLogger $audit): int
    {
        $action = (string) $this->argument('action');
        $actor = Actor::console($this->getName());

        return $this->inTenant(function (string $tenantId) use ($action, $audit, $actor): int {
            if ($action === 'list') {
                $this->table(['name', 'email', 'role', 'status'], DB::table('tenant_users as m')->join('users as u', 'u.id', '=', 'm.user_id')
                    ->where('m.tenant_id', $tenantId)->orderBy('u.name')->get(['u.name', 'u.email', 'm.role', 'm.status'])->map(fn ($r) => (array) $r)->all());

                return self::SUCCESS;
            }
            $email = Str::lower(trim((string) $this->option('email')));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->error('Pass a valid --email.');

                return self::FAILURE;
            }
            $role = $this->option('role') === null ? null : TenantRole::tryFrom((string) $this->option('role'));
            if (in_array($action, ['add', 'role'], true) && $role === null) {
                $this->error('Pass --role=owner|admin|marketer|approver|viewer.');

                return self::FAILURE;
            }
            $user = User::query()->where('email', $email)->first();
            $member = $user === null ? null : TenantUser::query()->where('user_id', $user->id)->first();

            if ($action === 'add') {
                if ($member !== null) {
                    $this->error('Already a member; use role/enable.');

                    return self::FAILURE;
                }
                $password = $this->option('password-stdin') ? trim((string) fgets(STDIN)) : (string) $this->secret('Password (empty: keep existing / random)');
                if ($password !== '' && strlen($password) < 12) {
                    $this->error('Password must be at least 12 characters.');

                    return self::FAILURE;
                }
                $user ??= User::query()->create(['email' => $email, 'name' => (string) ($this->option('name') ?: $email), 'password' => $password !== '' ? $password : Str::password(32)]);
                /** @var TenantRole $role */
                $member = TenantUser::query()->create(['user_id' => $user->id, 'role' => $role]);
                $audit->record(AuditAction::MemberAdded, 'tenant_user', (string) $member->id, ['role' => $role->value], $actor);
                $this->info("Added with role {$role->value}.");

                return self::SUCCESS;
            }
            if ($member === null) {
                $this->error('Not a member of this tenant.');

                return self::FAILURE;
            }
            if ($action === 'role') {
                /** @var TenantRole $role */
                $from = $member->role->value;
                $member->update(['role' => $role]);
                $audit->record(AuditAction::MemberRoleChanged, 'tenant_user', (string) $member->id, ['from' => $from, 'to' => $role->value], $actor);
                $this->info("Role changed {$from} → {$role->value}.");

                return self::SUCCESS;
            }
            if (in_array($action, ['disable', 'enable'], true)) {
                $member->update(['status' => $action === 'disable' ? 'disabled' : 'active']);
                $audit->record(AuditAction::MemberStatusChanged, 'tenant_user', (string) $member->id, ['status' => $member->status], $actor);
                $this->info("Member {$action}d.");

                return self::SUCCESS;
            }
            $this->error('Unknown action.');

            return self::FAILURE;
        });
    }
}
