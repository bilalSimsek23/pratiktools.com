<?php

namespace Tests\Feature\Panel;

use App\Audit\Actor;
use App\Http\Middleware\PanelRecentAuth;
use App\Http\Middleware\PanelTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\TenantLifecycle;
use App\Tenancy\ProvisioningError;
use App\Tenancy\TenantMembers;
use App\Tenancy\TenantRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Ayarlar › Ekip: a tenant's owner/admin invite teammates inside their own
 * tenant only, with role limits; the last owner is protected; invitations
 * are single use, expire, are revoked when replaced, and stop with a
 * suspended tenant.
 */
#[Group('tenant-isolation')]
final class TeamManagementTest extends TestCase
{
    use InteractsWithTenants;

    private Tenant $a;

    private Tenant $b;

    private User $ownerA;

    private User $ownerB;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost']);
        [$this->a, $this->ownerA] = $this->createTenant('igs-test');
        [$this->b, $this->ownerB] = $this->createTenant('abc-tekstil-test');
    }

    private function as(User $user, ?Tenant $tenant = null): self
    {
        return $this->actingAs($user, 'web')->withSession([PanelTenant::SESSION_TENANT => ($tenant ?? $this->a)->id, PanelRecentAuth::SESSION_KEY => time()]);
    }

    private function invite(User $user, array $body, ?Tenant $tenant = null): TestResponse
    {
        return $this->as($user, $tenant)->post('/app/team/invitations', $body + ['email' => 'yeni@example.test', 'name' => 'Yeni Kişi', 'role' => 'marketer']);
    }

    /**
     * @return Collection<int, object>
     */
    private function membersOf(Tenant $tenant): Collection
    {
        return collect(DB::connection('pgsql_platform')->select('SELECT * FROM app_platform_tenant_members(?)', [$tenant->id]));
    }

    private function membership(Tenant $tenant, User $user): string
    {
        return (string) $this->membersOf($tenant)->firstWhere('user_id', $user->id)->membership_id;
    }

    private function accept(string $url, string $password = 'Kendi-sifrem-2026-x'): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this->post($url, ['password' => $password, 'password_confirmation' => $password]);
    }

    public function test_owner_invites_into_their_own_tenant_only_and_the_teammate_activates(): void
    {
        $this->as($this->ownerA)->get('/app/team')->assertOk()->assertSee('Kullanıcı davet et')->assertSee($this->ownerA->email)->assertDontSee($this->ownerB->email);

        // A tenant id in the body is ignored: the tenant is the session membership.
        $this->invite($this->ownerA, ['tenant_id' => $this->b->id, 'tenant' => $this->b->id])->assertSessionHasNoErrors();
        $url = (string) session('invitation.url');
        $this->assertNotNull($this->membersOf($this->a)->firstWhere('email', 'yeni@example.test'));
        $this->assertNull($this->membersOf($this->b)->firstWhere('email', 'yeni@example.test'), 'Nothing reaches the other tenant.');
        $this->assertContains('tenant.user.invited', DB::connection('pgsql_platform')->table('audit_logs')->where('tenant_id', $this->a->id)->where('actor_type', 'user')->pluck('action')->all());

        $this->accept($url)->assertRedirect(route('panel.dashboard'));
        $this->assertSame([$this->a->id, 'active'], [session(PanelTenant::SESSION_TENANT), $this->membersOf($this->a)->firstWhere('email', 'yeni@example.test')->status]);
        $this->accept($url)->assertStatus(410, 'Used link.');
    }

    public function test_role_limits_for_owner_admin_and_the_other_roles(): void
    {
        $this->invite($this->ownerA, ['email' => 'o2@example.test', 'role' => 'owner'])->assertSessionHasErrors('action');
        $this->invite($this->ownerA, ['email' => 'adm@example.test', 'role' => 'admin'])->assertSessionHasNoErrors();

        $admin = $this->addMember($this->a, TenantRole::Admin);
        $this->invite($admin, ['email' => 'o3@example.test', 'role' => 'owner'])->assertSessionHasErrors('action');
        $this->invite($admin, ['email' => 'a2@example.test', 'role' => 'admin'])->assertSessionHasErrors('action');
        $this->invite($admin, ['email' => 'v@example.test', 'role' => 'viewer'])->assertSessionHasNoErrors();
        $viewerId = (string) $this->membersOf($this->a)->firstWhere('email', 'v@example.test')->membership_id;
        $this->as($admin)->post("/app/team/{$viewerId}/role", ['role' => 'owner'])->assertSessionHasErrors('action');
        $this->as($admin)->post("/app/team/{$viewerId}/role", ['role' => 'approver'])->assertSessionHasNoErrors();
        // Admin cannot touch an owner or another admin, nor themselves.
        $this->as($admin)->post('/app/team/'.$this->membership($this->a, $this->ownerA).'/status', ['status' => 'disabled'])->assertSessionHasErrors('action');
        $otherAdmin = $this->addMember($this->a, TenantRole::Admin);
        $this->as($admin)->post('/app/team/'.$this->membership($this->a, $otherAdmin).'/role', ['role' => 'viewer'])->assertSessionHasErrors('action');
        $this->as($admin)->post('/app/team/'.$this->membership($this->a, $admin).'/role', ['role' => 'marketer'])->assertSessionHasErrors('action');
        $this->assertSame('admin', $this->membersOf($this->a)->firstWhere('user_id', $otherAdmin->id)->role);

        foreach ([TenantRole::Marketer, TenantRole::Approver, TenantRole::Viewer] as $role) {
            $user = $this->addMember($this->a, $role);
            $this->invite($user, ['email' => "{$role->value}-x@example.test"])->assertForbidden();
            $this->as($user)->post("/app/team/{$viewerId}/status", ['status' => 'disabled'])->assertForbidden();
            $this->as($user)->post("/app/team/{$viewerId}/role", ['role' => 'viewer'])->assertForbidden();
        }
        $marketer = $this->addMember($this->a, TenantRole::Marketer);
        $this->as($marketer)->get('/app/team')->assertOk()->assertDontSee('Kullanıcı davet et');
        $this->as($this->addMember($this->a, TenantRole::Viewer))->get('/app/team')->assertForbidden();
        $this->assertNull($this->membersOf($this->a)->firstWhere('email', 'marketer-x@example.test'));
    }

    public function test_cross_tenant_membership_uuids_answer_404_and_change_nothing(): void
    {
        $this->as($this->ownerB, $this->b)->post('/app/team/invitations', ['email' => 'b-user@example.test', 'role' => 'viewer'])->assertSessionHasNoErrors();
        $bMember = (string) $this->membersOf($this->b)->firstWhere('email', 'b-user@example.test')->membership_id;
        $bOwner = $this->membership($this->b, $this->ownerB);

        foreach (["/app/team/{$bMember}/role" => ['role' => 'admin'], "/app/team/{$bMember}/status" => ['status' => 'disabled'],
            "/app/team/{$bMember}/invitation" => [], "/app/team/{$bOwner}/status" => ['status' => 'disabled']] as $url => $body) {
            $this->as($this->ownerA)->post($url, $body)->assertNotFound();
        }
        $this->assertSame(['viewer', 'invited'], [$this->membersOf($this->b)->firstWhere('email', 'b-user@example.test')->role, $this->membersOf($this->b)->firstWhere('email', 'b-user@example.test')->status]);
        $this->assertSame('active', $this->membersOf($this->b)->firstWhere('user_id', $this->ownerB->id)->status);
        // Switching the session to the other tenant without a membership is refused.
        $this->as($this->ownerA, $this->b)->get('/app/team')->assertRedirect(route('panel.tenants'));
    }

    public function test_the_last_active_owner_cannot_be_disabled_demoted_or_removed(): void
    {
        $members = app(TenantMembers::class);
        $owner = $this->membership($this->a, $this->ownerA);
        foreach ([fn () => $members->setStatus($this->a->id, $owner, 'disabled', Actor::system()), fn () => $members->changeRole($this->a->id, $owner, TenantRole::Admin, Actor::system())] as $attempt) {
            try {
                $attempt();
                $this->fail('Last owner must stay.');
            } catch (ProvisioningError $e) {
                $this->assertStringContainsString('owner', $e->getMessage());
            }
        }
        // The panel cannot change owners at all, the owner included.
        $this->as($this->ownerA)->post("/app/team/{$owner}/role", ['role' => 'admin'])->assertSessionHasErrors('action');
        $this->as($this->ownerA)->post("/app/team/{$owner}/status", ['status' => 'disabled'])->assertSessionHasErrors('action');
        $this->assertSame(0, DB::table('tenant_users')->count(), 'No context: nothing visible.');
        $this->assertSame(['owner', 'active'], [$this->membersOf($this->a)->firstWhere('user_id', $this->ownerA->id)->role, $this->membersOf($this->a)->firstWhere('user_id', $this->ownerA->id)->status]);

        // With a second active owner the first may step down (platform path).
        $second = $this->addMember($this->a, TenantRole::Owner);
        $members->changeRole($this->a->id, $owner, TenantRole::Admin, Actor::system());
        $this->assertSame('admin', $this->membersOf($this->a)->firstWhere('user_id', $this->ownerA->id)->role);
        $this->expectException(ProvisioningError::class);
        $members->setStatus($this->a->id, $this->membership($this->a, $second), 'disabled', Actor::system());
    }

    public function test_expired_replaced_and_used_invitations_are_refused(): void
    {
        $this->invite($this->ownerA, ['email' => 'gec@example.test'])->assertSessionHasNoErrors();
        $first = (string) session('invitation.url');
        $member = (string) $this->membersOf($this->a)->firstWhere('email', 'gec@example.test')->membership_id;
        $this->as($this->ownerA)->post("/app/team/{$member}/invitation")->assertSessionHasNoErrors();
        $second = (string) session('invitation.url');

        $this->accept($first)->assertStatus(410);
        $this->travel((int) config('platform.invitations.ttl_hours') + 1)->hours();
        $this->accept($second)->assertForbidden();
        $this->travelBack();
        // Still signed but expired in the database (e.g. a link issued with a longer signature) is refused too.
        $this->assertSame(1, $this->inTenant($this->a, fn () => DB::table('user_invitations')->where('membership_id', $member)->whereNull('revoked_at')->update(['expires_at' => now()->subMinute()])));
        $this->accept($second)->assertStatus(410);
        $this->assertSame('invited', $this->membersOf($this->a)->firstWhere('email', 'gec@example.test')->status);
    }

    public function test_a_suspended_tenant_blocks_acceptance_and_new_invitations(): void
    {
        $this->invite($this->ownerA, ['email' => 'askida@example.test'])->assertSessionHasNoErrors();
        $url = (string) session('invitation.url');
        app(TenantLifecycle::class)->suspend($this->a->id, 'Test', Actor::system());

        $this->accept($url)->assertStatus(410);
        $this->invite($this->ownerA, ['email' => 'baska@example.test'])->assertForbidden();
        try {
            app(TenantMembers::class)->invite($this->a->id, 'platform@example.test', 'P', TenantRole::Viewer, Actor::system());
            $this->fail('No invitation for a suspended tenant.');
        } catch (ProvisioningError $e) {
            $this->assertStringContainsString('askıda', $e->getMessage());
        }
        $this->assertNull($this->membersOf($this->a)->firstWhere('email', 'platform@example.test'), 'Rolled back with the refused invitation.');
        $this->assertSame('invited', $this->membersOf($this->a)->firstWhere('email', 'askida@example.test')->status);

        app(TenantLifecycle::class)->reactivate($this->a->id, Actor::system());
        $this->accept($url)->assertRedirect(route('panel.dashboard'));
    }
}
