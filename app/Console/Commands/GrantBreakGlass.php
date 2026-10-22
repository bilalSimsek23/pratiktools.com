<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use App\Platform\BreakGlass;
use App\Tenancy\TenantDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class GrantBreakGlass extends Command
{
    protected $signature = 'platform:break-glass {admin-email} {tenant-slug} {--minutes=60} {--reason=}';

    protected $description = 'Record an audited, time-boxed emergency access grant for a Super Admin';

    public function handle(BreakGlass $breakGlass, TenantDirectory $directory): int
    {
        $reason = trim((string) $this->option('reason'));
        $admin = PlatformAdmin::query()->where('email', Str::lower((string) $this->argument('admin-email')))->where('status', 'active')->first();
        $tenantId = $directory->idBySlug((string) $this->argument('tenant-slug'));

        if ($admin === null || $tenantId === null || strlen($reason) < 10) {
            $this->error('Unknown admin or tenant, or --reason shorter than 10 characters.');

            return self::FAILURE;
        }

        $grant = $breakGlass->grant($admin, $tenantId, $reason, (int) $this->option('minutes'));
        $this->info("Break-glass grant {$grant->id} recorded until {$grant->expires_at->toIso8601String()}.");

        return self::SUCCESS;
    }
}
