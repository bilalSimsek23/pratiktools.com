<?php

namespace Tests\Feature;

use App\Audit\Exceptions\AuditLogImmutable;
use App\Models\AuditLog;
use App\Models\PlatformAdmin;
use App\Platform\BreakGlass;
use App\Tenancy\TenantRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class AuditLogTest extends TestCase
{
    use InteractsWithTenants;

    public function test_security_relevant_actions_are_audited(): void
    {
        [$a, $owner] = $this->createTenant('alpha');
        $viewer = $this->addMember($a, TenantRole::Viewer);
        $membershipId = $this->inTenant($a, fn () => $viewer->memberships()->value('id'));

        $login = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => self::PASSWORD, 'tenant' => 'alpha'])->assertOk();
        $token = $login->json('token');
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'wrong-password', 'tenant' => 'alpha'])->assertUnprocessable();

        $this->api('PATCH', '/api/v1/tenant/settings', $token, ['timezone' => 'Europe/Berlin'])->assertOk();
        $this->api('PATCH', "/api/v1/members/{$membershipId}/role", $token, ['role' => 'marketer'])->assertOk();
        $id = $this->api('POST', '/api/v1/integrations', $token, ['provider' => 'klaviyo', 'capability' => 'email', 'secret' => 'first-secret-0001'])->json('data.id');
        $this->api('PUT', "/api/v1/integrations/{$id}/secret", $token, ['secret' => 'second-secret-0002'])->assertOk();
        $this->api('POST', "/api/v1/integrations/{$id}/verify", $token)->assertAccepted();
        $this->api('POST', '/api/v1/auth/logout', $token)->assertOk();

        $actions = $this->inTenant($a, fn () => AuditLog::query()->pluck('action')->all());

        foreach ([
            'tenant.created', 'vault.data_key_created', 'auth.login', 'tenant.settings_updated', 'member.role_changed',
            'integration.created', 'integration.secret_created', 'integration.secret_changed',
            'secret.decrypted', 'integration.verified', 'auth.logout',
        ] as $expected) {
            $this->assertContains($expected, $actions, "Missing audit entry {$expected}");
        }

        // Failed logins are platform-level (no tenant is trusted yet).
        $this->assertSame(1, DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'auth.login_failed')->whereNull('tenant_id')->count());

        $roleChange = $this->inTenant($a, fn () => AuditLog::query()->where('action', 'member.role_changed')->sole());
        $this->assertEquals(['user_id' => $viewer->id, 'from' => 'viewer', 'to' => 'marketer'], $roleChange->metadata);
        $this->assertSame('user', $roleChange->actor_type);
        $this->assertSame($owner->id, $roleChange->actor_id);
        $this->assertNotNull($roleChange->ip_hash);
        $this->assertNotSame('127.0.0.1', $roleChange->ip_hash);
    }

    public function test_break_glass_is_audited_on_the_tenant_trail(): void
    {
        [$a] = $this->createTenant('alpha');
        $admin = $this->makeAdmin();

        $grant = app(BreakGlass::class)->grant($admin, $a->id, 'Investigating sync incident INC-42', 30);

        $entry = $this->inTenant($a, fn () => AuditLog::query()->where('action', 'platform.break_glass_granted')->sole());
        $this->assertSame('platform_admin', $entry->actor_type);
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame($grant->id, $entry->resource_id);
    }

    public function test_audit_log_is_append_only_in_the_application(): void
    {
        [$a] = $this->createTenant('alpha');
        $entry = $this->inTenant($a, fn () => AuditLog::query()->first());

        try {
            $this->inTenant($a, fn () => $entry->forceFill(['action' => 'tampered'])->save());
            $this->fail('Audit update must be rejected.');
        } catch (AuditLogImmutable) {
        }

        $this->expectException(AuditLogImmutable::class);
        $this->inTenant($a, fn () => $entry->delete());
    }

    public function test_audit_log_is_append_only_in_the_database_for_every_role(): void
    {
        [$a] = $this->createTenant('alpha');

        $before = $this->inTenant($a, fn () => AuditLog::query()->count());
        $this->assertGreaterThan(0, $before);

        // Runtime and platform roles have no UPDATE/DELETE privilege at all.
        foreach (['UPDATE audit_logs SET action = ?', 'DELETE FROM audit_logs WHERE action <> ?'] as $sql) {
            foreach (['pgsql', 'pgsql_platform'] as $connection) {
                try {
                    $this->inTenant($a, fn () => DB::connection($connection)->statement($sql, ['tampered']));
                    $this->fail("{$connection} must not be able to run: {$sql}");
                } catch (QueryException $e) {
                    $this->assertStringContainsString('permission denied', $e->getMessage());
                }
            }
        }

        // The owner cannot see any row (FORCE RLS), so it cannot change any.
        $this->assertSame(0, DB::connection('pgsql_owner')->update('UPDATE audit_logs SET action = ?', ['tampered']));
        $this->assertSame(0, DB::connection('pgsql_owner')->delete('DELETE FROM audit_logs'));

        // And a trigger rejects UPDATE/DELETE for any role that could see rows.
        $trigger = DB::connection('pgsql_owner')->selectOne(
            "SELECT tgenabled FROM pg_trigger WHERE tgname = 'audit_logs_append_only' AND tgrelid = 'audit_logs'::regclass"
        );
        $this->assertSame('O', $trigger?->tgenabled);

        $this->assertSame($before, $this->inTenant($a, fn () => AuditLog::query()->count()));
        $this->assertSame(0, $this->inTenant($a, fn () => AuditLog::query()->where('action', 'tampered')->count()));
    }

    private function makeAdmin(): PlatformAdmin
    {
        $admin = new PlatformAdmin(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'a-very-long-admin-password', 'status' => 'active']);
        $admin->setConnection('pgsql_owner')->save();

        return PlatformAdmin::query()->findOrFail($admin->id);
    }
}
