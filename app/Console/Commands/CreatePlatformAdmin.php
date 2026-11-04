<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Super Admins are created only from the console, through the schema owner
 * connection; the web-facing platform role cannot insert admins. Needs an
 * interactive terminal for the hidden password prompt; without one (Laravel
 * Cloud Commands) use platform:admin:invite.
 */
final class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:admin:create {email} {name}';

    protected $description = 'Create a Super Admin (two-factor setup is enforced at first login)';

    public function handle(AuditLogger $audit): int
    {
        $password = (string) $this->secret('Password (min 16 characters)');

        $validator = Validator::make(
            ['email' => $this->argument('email'), 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', 'string', 'min:16']],
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $admin = new PlatformAdmin(['name' => (string) $this->argument('name'), 'email' => Str::lower((string) $this->argument('email')), 'password' => $password, 'status' => 'active']);
        $admin->setConnection('pgsql_owner')->save();
        $audit->record(AuditAction::PlatformAdminCreated, 'platform_admin', $admin->id, [], Actor::console($this->getName()), tenantId: false, connection: 'pgsql_platform');

        $this->info("Super Admin {$admin->email} created. Two-factor setup is required at first login.");

        return self::SUCCESS;
    }
}
