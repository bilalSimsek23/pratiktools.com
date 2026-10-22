<?php

namespace Tests\Feature;

use App\Models\PersonalAccessToken;
use App\Tenancy\Permission;
use App\Tenancy\TenantRole;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class AuthAndAuthorizationTest extends TestCase
{
    use InteractsWithTenants;

    public function test_login_issues_a_token_bound_to_the_requested_tenant(): void
    {
        [$a, $owner] = $this->createTenant('alpha');

        $response = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => self::PASSWORD, 'tenant' => 'alpha'])
            ->assertOk()
            ->assertJsonPath('tenant_id', $a->id)
            ->assertJsonPath('role', 'owner');

        $this->assertSame($a->id, PersonalAccessToken::findToken($response->json('token'))->tenant_id);
        $this->assertNotNull(PersonalAccessToken::findToken($response->json('token'))->expires_at);
    }

    public function test_login_fails_identically_for_wrong_password_unknown_user_and_foreign_tenant(): void
    {
        [, $owner] = $this->createTenant('alpha');
        $this->createTenant('beta');

        $responses = [
            $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'wrong', 'tenant' => 'alpha']),
            $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'x', 'tenant' => 'alpha']),
            $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => self::PASSWORD, 'tenant' => 'beta']),
            $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => self::PASSWORD, 'tenant' => 'nope']),
        ];

        foreach ($responses as $response) {
            $response->assertUnprocessable()->assertJsonPath('errors.email.0', __('auth.failed'));
        }
    }

    public function test_login_is_throttled(): void
    {
        [, $owner] = $this->createTenant('alpha');
        RateLimiter::clear('email:'.$owner->email.'|127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'wrong', 'tenant' => 'alpha'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => self::PASSWORD, 'tenant' => 'alpha'])->assertStatus(429);
    }

    public function test_logout_revokes_the_token(): void
    {
        [$a, $owner] = $this->createTenant('alpha');
        $token = $this->tokenFor($owner, $a);

        $this->api('POST', '/api/v1/auth/logout', $token)->assertOk();
        $this->api('GET', '/api/v1/tenant', $token)->assertUnauthorized();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/tenant')->assertUnauthorized();
        $this->getJson('/api/v1/customers')->assertUnauthorized();
    }

    public function test_role_matrix_is_enforced_on_endpoints(): void
    {
        [$a, $owner] = $this->createTenant('alpha');
        $integration = $this->createIntegration($a);
        $tokens = ['owner' => $this->tokenFor($owner, $a)];
        foreach ([TenantRole::Admin, TenantRole::Marketer, TenantRole::Viewer] as $role) {
            $tokens[$role->value] = $this->tokenFor($this->addMember($a, $role), $a);
        }

        $expect = [
            // [method, uri, data, owner, admin, marketer, viewer]
            ['GET', '/api/v1/tenant', [], 200, 200, 200, 200],
            ['GET', '/api/v1/customers', [], 200, 200, 200, 200],
            ['GET', '/api/v1/members', [], 200, 200, 200, 403],
            ['GET', '/api/v1/integrations', [], 200, 200, 200, 403],
            ['GET', '/api/v1/audit-logs', [], 200, 200, 403, 403],
            ['PATCH', '/api/v1/tenant/settings', ['timezone' => 'Europe/Istanbul'], 200, 200, 403, 403],
            ['PATCH', '/api/v1/tenant/settings', ['sending_mode' => 'internal'], 200, 403, 403, 403],
            ['PUT', "/api/v1/integrations/{$integration->id}/secret", ['secret' => 'rotated-secret-0001'], 200, 200, 403, 403],
        ];

        foreach ($expect as [$method, $uri, $data, $o, $ad, $m, $v]) {
            foreach (['owner' => $o, 'admin' => $ad, 'marketer' => $m, 'viewer' => $v] as $role => $status) {
                $this->api($method, $uri, $tokens[$role], $data)->assertStatus($status);
            }
        }
    }

    public function test_admin_cannot_grant_or_remove_owner_role_and_last_owner_is_kept(): void
    {
        [$a, $owner] = $this->createTenant('alpha');
        $admin = $this->addMember($a, TenantRole::Admin);
        $viewer = $this->addMember($a, TenantRole::Viewer);
        [$ownerMembership, $viewerMembership] = $this->inTenant($a, fn () => [$owner->memberships()->value('id'), $viewer->memberships()->value('id')]);
        $adminToken = $this->tokenFor($admin, $a);

        $this->api('PATCH', "/api/v1/members/{$viewerMembership}/role", $adminToken, ['role' => 'owner'])->assertForbidden();
        $this->api('PATCH', "/api/v1/members/{$ownerMembership}/role", $adminToken, ['role' => 'viewer'])->assertForbidden();
        $this->api('PATCH', "/api/v1/members/{$viewerMembership}/role", $adminToken, ['role' => 'marketer'])->assertOk();

        $this->api('PATCH', "/api/v1/members/{$ownerMembership}/role", $this->tokenFor($owner, $a), ['role' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_role_permissions_match_the_documented_matrix(): void
    {
        $this->assertCount(count(Permission::cases()), TenantRole::Owner->permissions());
        $this->assertFalse(TenantRole::Admin->allows(Permission::SendingModeUpdate));
        $this->assertFalse(TenantRole::Marketer->allows(Permission::IntegrationsManage));
        $this->assertFalse(TenantRole::Marketer->allows(Permission::AuditView));
        $this->assertSame([Permission::TenantView, Permission::CustomersView], TenantRole::Viewer->permissions());
    }
}
