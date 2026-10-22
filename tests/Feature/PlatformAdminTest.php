<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePlatformTwoFactor;
use App\Models\PlatformAdmin;
use App\Tenancy\TenantContext;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class PlatformAdminTest extends TestCase
{
    use InteractsWithTenants;

    private string $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = 'http://'.config('platform.admin_domain');
    }

    public function test_admin_must_complete_two_factor_before_seeing_the_dashboard(): void
    {
        $admin = $this->makeAdmin();

        $this->post("{$this->host}/login", ['email' => $admin->email, 'password' => 'a-very-long-admin-password'])
            ->assertRedirect(route('admin.two-factor.setup'));
        $this->get("{$this->host}/")->assertRedirect(route('admin.two-factor.setup'));

        $secret = session('platform.two_factor_pending_secret') ?? $this->get("{$this->host}/two-factor/setup")->viewData('secret');
        $this->post("{$this->host}/two-factor/setup", ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post("{$this->host}/two-factor/setup", ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
            ->assertRedirect(route('admin.dashboard'));

        $this->get("{$this->host}/")->assertOk()->assertSee('Tenants');
        $this->assertNotNull($admin->refresh()->two_factor_confirmed_at);
    }

    public function test_dashboard_lists_tenants_without_personal_data(): void
    {
        [$a] = $this->createTenant('alpha');
        $this->createCustomer($a, 'customer@alpha.test');
        $this->createIntegration($a);

        $response = $this->actingAsTwoFactorAdmin()->get("{$this->host}/")->assertOk();

        $response->assertSee('alpha')->assertSee('klaviyo/email: pending');
        $response->assertDontSee('owner@alpha.test');
        $response->assertDontSee('customer@alpha.test');
        $this->assertStringContainsString("script-src 'self' 'nonce-", $response->headers->get('Content-Security-Policy'));
        $this->assertFalse(app(TenantContext::class)->hasTenant(), 'Admin panel must never set a tenant context.');
    }

    public function test_admin_routes_are_not_served_on_the_tenant_host_and_tenant_api_rejects_admin_host(): void
    {
        [$a, $owner] = $this->createTenant('alpha');

        $this->get('/login')->assertNotFound();
        $this->get('/two-factor')->assertNotFound();

        $this->api('GET', "{$this->host}/api/v1/tenant", $this->tokenFor($owner, $a))->assertNotFound();
    }

    public function test_tenant_token_cannot_access_admin_and_admin_session_cannot_access_tenant_api(): void
    {
        [$a, $owner] = $this->createTenant('alpha');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner, $a))->get("{$this->host}/")
            ->assertRedirect(route('admin.login'));

        // Drop the tenant bearer header from the first request; only the admin session remains.
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        // Absolute URL: the test client otherwise keeps the previous (admin) host.
        $this->actingAsTwoFactorAdmin()->getJson('http://localhost/api/v1/tenant')->assertUnauthorized();
    }

    public function test_horizon_requires_a_two_factor_admin(): void
    {
        $this->get("{$this->host}/horizon")->assertRedirect(route('admin.login'));

        $this->actingAsTwoFactorAdmin()->get("{$this->host}/horizon")->assertOk();
    }

    private function makeAdmin(): PlatformAdmin
    {
        $admin = new PlatformAdmin(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'a-very-long-admin-password', 'status' => 'active']);
        $admin->setConnection('pgsql_owner')->save();

        return PlatformAdmin::query()->findOrFail($admin->id);
    }

    private function actingAsTwoFactorAdmin(): static
    {
        $admin = $this->makeAdmin();
        $admin->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(32), 'two_factor_confirmed_at' => now()])->save();

        return $this->actingAs($admin, 'platform')->withSession([EnsurePlatformTwoFactor::SESSION_KEY => $admin->id]);
    }
}
