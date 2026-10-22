<?php

namespace Tests\Feature\Isolation;

use App\Models\Customer;
use App\Models\TenantIntegration;
use App\Tenancy\Exceptions\TenantContextMissing;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantRole;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

#[Group('tenant-isolation')]
final class HttpTenantIsolationTest extends TestCase
{
    use InteractsWithTenants;

    public function test_tenant_sees_its_own_customers_and_not_another_tenants(): void
    {
        [$a, $ownerA] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');
        $mine = $this->createCustomer($a, 'mine@alpha.test');
        $theirs = $this->createCustomer($b, 'theirs@beta.test');

        $response = $this->api('GET', '/api/v1/customers', $this->tokenFor($ownerA, $a))->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
        $this->assertStringNotContainsString('theirs@beta.test', $response->getContent());
    }

    public function test_guessing_another_tenants_record_id_returns_not_found(): void
    {
        [$a, $ownerA] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');
        $theirs = $this->createCustomer($b, 'theirs@beta.test');
        $integration = $this->createIntegration($b);
        $token = $this->tokenFor($ownerA, $a);

        $this->api('GET', "/api/v1/customers/{$theirs->id}", $token)->assertNotFound();
        $this->api('GET', "/api/v1/integrations/{$integration->id}", $token)->assertNotFound();
    }

    public function test_cannot_update_or_delete_another_tenants_records(): void
    {
        [$a, $ownerA] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');
        $integration = $this->createIntegration($b, 'beta-original-secret-0001');
        $originalEnvelope = $this->inTenant($b, fn () => TenantIntegration::query()->find($integration->id)->encrypted_secret);
        $token = $this->tokenFor($ownerA, $a);

        $this->api('PUT', "/api/v1/integrations/{$integration->id}/secret", $token, ['secret' => 'attacker-secret-0001'])->assertNotFound();
        $this->api('POST', "/api/v1/integrations/{$integration->id}/verify", $token)->assertNotFound();
        $this->api('DELETE', "/api/v1/integrations/{$integration->id}", $token)->assertNotFound();

        $this->inTenant($b, function () use ($integration, $originalEnvelope) {
            $fresh = TenantIntegration::query()->find($integration->id);
            $this->assertNotNull($fresh);
            $this->assertSame($originalEnvelope, $fresh->encrypted_secret);
        });
    }

    public function test_cannot_change_roles_of_another_tenants_members(): void
    {
        [$a, $ownerA] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');
        $memberB = $this->addMember($b, TenantRole::Viewer);
        $membershipId = $this->inTenant($b, fn () => $memberB->memberships()->value('id'));

        $this->api('PATCH', "/api/v1/members/{$membershipId}/role", $this->tokenFor($ownerA, $a), ['role' => 'owner'])->assertNotFound();
    }

    public function test_tenant_cannot_be_selected_through_request_input_or_headers(): void
    {
        [$a, $ownerA] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');
        $this->createCustomer($b, 'theirs@beta.test');

        $response = $this->api('GET', "/api/v1/customers?tenant_id={$b->id}&tenant=beta", $this->tokenFor($ownerA, $a), [], [
            'X-Tenant-Id' => $b->id,
            'X-Tenant' => 'beta',
        ])->assertOk();

        $this->assertStringNotContainsString('theirs@beta.test', $response->getContent());
        $this->assertSame($a->id, $this->api('GET', '/api/v1/tenant', $this->tokenFor($ownerA, $a), [], ['X-Tenant-Id' => $b->id])->json('data.id'));
    }

    public function test_token_is_bound_to_one_tenant_even_for_a_member_of_both(): void
    {
        [$a, $ownerA] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');
        $this->addMember($b, TenantRole::Admin, $ownerA);
        $this->createCustomer($b, 'theirs@beta.test');

        $tokenA = $this->tokenFor($ownerA, $a);
        $this->assertSame($a->id, $this->api('GET', '/api/v1/tenant', $tokenA)->json('data.id'));
        $this->assertStringNotContainsString('theirs@beta.test', $this->api('GET', '/api/v1/customers', $tokenA)->getContent());

        $tokenB = $this->tokenFor($ownerA, $b);
        $this->assertSame($b->id, $this->api('GET', '/api/v1/tenant', $tokenB)->json('data.id'));
    }

    public function test_consecutive_requests_for_different_tenants_do_not_leak_context(): void
    {
        [$a, $ownerA] = $this->createTenant('alpha');
        [$b, $ownerB] = $this->createTenant('beta');
        $this->createCustomer($a, 'mine@alpha.test');
        $this->createCustomer($b, 'theirs@beta.test');

        $first = $this->api('GET', '/api/v1/customers', $this->tokenFor($ownerA, $a));
        $this->assertFalse(app(TenantContext::class)->hasTenant(), 'Context must be cleared after the request.');

        $second = $this->api('GET', '/api/v1/customers', $this->tokenFor($ownerB, $b));

        $this->assertStringContainsString('mine@alpha.test', $first->getContent());
        $this->assertStringNotContainsString('theirs@beta.test', $first->getContent());
        $this->assertStringContainsString('theirs@beta.test', $second->getContent());
        $this->assertStringNotContainsString('mine@alpha.test', $second->getContent());
    }

    public function test_context_is_cleared_even_when_the_request_fails(): void
    {
        [$a, $ownerA] = $this->createTenant('alpha');

        $this->api('PATCH', '/api/v1/tenant/settings', $this->tokenFor($ownerA, $a), ['timezone' => 'Not/AZone'])->assertUnprocessable();

        $this->assertFalse(app(TenantContext::class)->hasTenant());
        $this->expectException(TenantContextMissing::class);
        Customer::query()->count();
    }

    public function test_removed_member_loses_access_with_an_existing_token(): void
    {
        [$a] = $this->createTenant('alpha');
        $marketer = $this->addMember($a, TenantRole::Marketer);
        $token = $this->tokenFor($marketer, $a);
        $this->api('GET', '/api/v1/tenant', $token)->assertOk();

        $this->inTenant($a, fn () => $marketer->memberships()->update(['status' => 'disabled']));

        $this->api('GET', '/api/v1/tenant', $token)->assertForbidden();
    }

    public function test_suspended_tenant_is_refused(): void
    {
        [$a, $ownerA] = $this->createTenant('alpha');
        $token = $this->tokenFor($ownerA, $a);

        $this->inTenant($a, fn () => $a->forceFill(['status' => 'suspended'])->save());

        $this->api('GET', '/api/v1/tenant', $token)->assertForbidden();
    }
}
