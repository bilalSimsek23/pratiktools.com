<?php

namespace Tests\Concerns;

use App\Models\Customer;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\TenantUser;
use App\Models\User;
use App\Security\Vault\SecretVault;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantProvisioner;
use App\Tenancy\TenantRole;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait InteractsWithTenants
{
    protected const PASSWORD = 'correct-horse-battery-staple';

    /**
     * @return array{0: Tenant, 1: User}
     */
    protected function createTenant(string $slug): array
    {
        $tenant = app(TenantProvisioner::class)->provision(
            ['name' => Str::title($slug), 'slug' => $slug, 'country' => 'TR', 'timezone' => 'Europe/Istanbul', 'currency' => 'TRY', 'locale' => 'tr'],
            ['email' => "owner@{$slug}.test", 'name' => "Owner {$slug}", 'password' => self::PASSWORD],
        );

        return [$tenant, User::query()->where('email', "owner@{$slug}.test")->firstOrFail()];
    }

    protected function addMember(Tenant $tenant, TenantRole $role, ?User $user = null): User
    {
        $user ??= User::query()->create([
            'name' => "{$role->value} {$tenant->slug}",
            'email' => Str::lower(Str::random(8))."@{$tenant->slug}.test",
            'password' => self::PASSWORD,
        ]);

        $this->inTenant($tenant, fn () => TenantUser::query()->create(['user_id' => $user->id, 'role' => $role]));

        return $user;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function inTenant(Tenant $tenant, Closure $callback): mixed
    {
        return app(TenantContext::class)->run($tenant->id, $callback);
    }

    protected function createCustomer(Tenant $tenant, string $email): Customer
    {
        return $this->inTenant($tenant, fn () => Customer::query()->create(['email' => $email, 'first_name' => 'Test']));
    }

    protected function createIntegration(Tenant $tenant, string $secret = 'provider-secret-value-0001', string $provider = 'klaviyo', string $capability = 'email'): TenantIntegration
    {
        return $this->inTenant($tenant, function () use ($secret, $provider, $capability) {
            $integration = new TenantIntegration(['provider' => $provider, 'capability' => $capability]);
            app(SecretVault::class)->seal($integration, $secret);
            $integration->save();

            return $integration;
        });
    }

    protected function tokenFor(User $user, Tenant $tenant): string
    {
        $issued = $user->createToken('test');
        /** @var PersonalAccessToken $token */
        $token = $issued->accessToken;
        $token->forceFill(['tenant_id' => $tenant->id])->save();

        return $issued->plainTextToken;
    }

    /**
     * Sends an API request with a bearer token, resetting cached guards so
     * consecutive requests with different tokens behave like separate HTTP
     * requests.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    protected function api(string $method, string $uri, string $token, array $data = [], array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders($headers + ['Authorization' => "Bearer {$token}"])->json($method, $uri, $data);
    }
}
