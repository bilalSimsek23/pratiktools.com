<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Commerce\ConnectionTester;
use App\Console\Commands\Concerns\FindsCommerceIntegration;
use App\Console\Commands\Concerns\RunsInTenantContext;
use Illuminate\Console\Command;

final class TestCommerceConnection extends Command
{
    use FindsCommerceIntegration, RunsInTenantContext;

    protected $signature = 'commerce:test {--tenant= : Tenant slug}';

    protected $description = 'Read-only connection test of the tenant\'s commerce integration';

    public function handle(ConnectionTester $tester): int
    {
        return $this->inTenant(function () use ($tester) {
            $integration = $this->commerceIntegration();
            $check = $tester->test($integration, Actor::console($this->getName()));

            foreach ($check->checks as $name => $passed) {
                $this->line(sprintf('  %-14s %s', $name, $passed ? 'ok' : 'FAILED'));
            }
            $this->line("Status: {$integration->status}".($check->message ? " ({$check->errorCategory}: {$check->message})" : ''));

            return $check->ok ? self::SUCCESS : self::FAILURE;
        });
    }
}
