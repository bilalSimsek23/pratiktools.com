<?php

namespace App\Tenancy;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantUser;
use App\Models\User;
use App\Security\Vault\SecretVault;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a tenant with its settings, its owner membership and its first
 * data encryption key, all inside the new tenant's own context so that the
 * row-level security policies apply to provisioning as well.
 */
final class TenantProvisioner
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SecretVault $vault,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, slug: string, country: string, timezone: string, currency: string, locale: string}  $tenant
     * @param  array{email: string, name: string, password?: ?string}  $owner
     */
    public function provision(array $tenant, array $owner, ?Actor $actor = null): Tenant
    {
        $tenantId = (string) Str::uuid7();

        return $this->context->run($tenantId, fn () => DB::transaction(function () use ($tenantId, $tenant, $owner, $actor) {
            $model = Tenant::query()->create([
                'id' => $tenantId,
                'public_id' => Str::lower(Str::random(24)),
                'name' => $tenant['name'],
                'slug' => Str::lower($tenant['slug']),
                'status' => Tenant::STATUS_ACTIVE,
                'country' => Str::upper($tenant['country']),
                'timezone' => $tenant['timezone'],
                'currency' => Str::upper($tenant['currency']),
                'locale' => $tenant['locale'],
            ]);

            TenantSetting::query()->create([]);

            $user = User::query()->where('email', Str::lower($owner['email']))->first()
                ?? User::query()->create([
                    'email' => Str::lower($owner['email']),
                    'name' => $owner['name'],
                    'password' => $owner['password'] ?? Str::password(32),
                ]);

            TenantUser::query()->create(['user_id' => $user->id, 'role' => TenantRole::Owner]);

            $this->vault->ensureDataKey();

            $this->audit->record(AuditAction::TenantCreated, 'tenant', $tenantId, [
                'slug' => $model->slug,
                'country' => $model->country,
            ], $actor);

            return $model;
        }));
    }
}
