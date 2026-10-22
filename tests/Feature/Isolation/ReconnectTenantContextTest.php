<?php

namespace Tests\Feature\Isolation;

use App\Models\Customer;
use App\Queue\TenantAwareJob;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Long-lived workers (Horizon) lose and re-open database connections. The
 * tenant context must be re-applied to every new PostgreSQL session, and a
 * reconnect must never fall back to "no filter" or to another tenant.
 */
#[Group('tenant-isolation')]
final class ReconnectTenantContextTest extends TestCase
{
    use InteractsWithTenants;

    public function test_explicit_reconnect_reapplies_the_tenant_context(): void
    {
        [$a] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');
        $this->createCustomer($a, 'a1@alpha.test');
        $theirs = $this->createCustomer($b, 'b1@beta.test');

        $this->inTenant($a, function () use ($a, $theirs) {
            $before = $this->backendPid();
            DB::reconnect('pgsql');

            $this->assertNotSame($before, $this->backendPid(), 'A new PostgreSQL session must have been opened.');
            $this->assertSame($a->id, $this->sessionTenant());
            $this->assertSame(['a1@alpha.test'], array_column(DB::select('SELECT email FROM customers'), 'email'));
            $this->assertNull(Customer::query()->find($theirs->id));
        });
    }

    public function test_lazy_reconnect_after_disconnect_reapplies_the_tenant_context(): void
    {
        [$a] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');
        $theirs = $this->createCustomer($b, 'b1@beta.test');

        $this->inTenant($a, function () use ($a, $theirs) {
            DB::disconnect('pgsql');

            // The next query reconnects implicitly.
            $this->assertSame(0, count(DB::select('SELECT id FROM customers WHERE id = ?', [$theirs->id])));
            $this->assertSame($a->id, $this->sessionTenant());
        });
    }

    public function test_reconnect_after_the_server_terminated_the_session(): void
    {
        [$a] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');
        $this->createCustomer($a, 'a1@alpha.test');
        $this->createCustomer($b, 'b1@beta.test');

        $this->inTenant($a, function () use ($a) {
            $pid = $this->backendPid();
            // The session terminates itself, as a server restart or idle
            // timeout would; Laravel's lost-connection retry reconnects.
            $this->terminateOwnSession($pid);

            $emails = array_column(DB::select('SELECT email FROM customers'), 'email');

            $this->assertNotSame($pid, $this->backendPid());
            $this->assertSame($a->id, $this->sessionTenant());
            $this->assertSame(['a1@alpha.test'], $emails);
        });
    }

    public function test_reconnect_without_context_stays_closed(): void
    {
        [$a] = $this->createTenant('alpha');
        $this->createCustomer($a, 'a1@alpha.test');

        DB::reconnect('pgsql');

        $this->assertSame('', $this->sessionTenant());
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM customers')->n);
    }

    public function test_worker_job_for_tenant_a_reconnects_and_still_cannot_read_tenant_b(): void
    {
        [$a] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');
        $this->createCustomer($a, 'a1@alpha.test');
        $theirs = $this->createCustomer($b, 'b1@beta.test');
        ReconnectingProbeJob::$results = [];

        dispatch_sync(new ReconnectingProbeJob($a->id, $theirs->id));
        dispatch_sync(new ReconnectingProbeJob($b->id, $theirs->id));

        [$first, $second] = ReconnectingProbeJob::$results;
        $this->assertSame($a->id, $first['session_tenant']);
        $this->assertFalse($first['found_target']);
        $this->assertSame(['a1@alpha.test'], $first['emails']);
        $this->assertSame($b->id, $second['session_tenant']);
        $this->assertTrue($second['found_target']);
        $this->assertSame('', $this->sessionTenant(), 'Context must be cleared after the jobs.');
    }

    private function backendPid(): int
    {
        return (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    }

    private function sessionTenant(): string
    {
        return (string) DB::selectOne("SELECT current_setting('app.tenant_id', true) AS t")->t;
    }

    private function terminateOwnSession(int $pid): void
    {
        try {
            DB::select('SELECT pg_terminate_backend(?)', [$pid]);
        } catch (\Throwable) {
            // Terminating its own backend drops the connection mid-statement.
        }
    }
}

final class ReconnectingProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    /** @var list<array<string, mixed>> */
    public static array $results = [];

    public function __construct(string $tenantId, public readonly string $targetId)
    {
        $this->forTenant($tenantId);
    }

    public function handle(TenantContext $context): void
    {
        DB::reconnect('pgsql');

        self::$results[] = [
            'context' => $context->tenantId(),
            'session_tenant' => (string) DB::selectOne("SELECT current_setting('app.tenant_id', true) AS t")->t,
            'found_target' => Customer::query()->whereKey($this->targetId)->exists(),
            'emails' => array_column(DB::select('SELECT email FROM customers ORDER BY email'), 'email'),
        ];
    }
}
