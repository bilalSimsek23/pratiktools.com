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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a tenant with its settings, its owner membership, its first data
 * encryption key and (unless the operator set a password) an owner
 * invitation — in one transaction inside the new tenant's own context, so
 * row-level security applies to provisioning as well and a failure leaves
 * nothing behind. Used by `tenant:create` and the Super Admin panel.
 *
 * Idempotent: provisioning the same slug again for the same owner returns
 * the existing tenant (created = false) and creates nothing; a slug that
 * belongs to another owner is refused. It never touches the website owner
 * setting, integrations (all start "not configured") or sending modes.
 */
final class TenantProvisioner
{
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{1,62}$/';

    /** Slugs that would be confusing as customer codes. */
    public const RESERVED_SLUGS = ['admin', 'app', 'api', 'www', 'platform', 'system', 'root', 'support', 'destek', 'pratiktools-admin'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly SecretVault $vault,
        private readonly AuditLogger $audit,
        private readonly TenantInvitations $invitations,
        private readonly TenantDirectory $directory,
    ) {}

    public static function validSlug(string $slug): bool
    {
        return preg_match(self::SLUG_PATTERN, $slug) === 1 && ! str_ends_with($slug, '-') && ! str_contains($slug, '--') && ! in_array($slug, self::RESERVED_SLUGS, true);
    }

    /**
     * @param  array{name: string, slug: string, country: string, timezone: string, currency: string, locale: string, contact_phone?: ?string}  $tenant
     * @param  array{email: string, name: string, password?: ?string}  $owner  password: operator CLI only (activated account, no invitation)
     */
    public function provision(array $tenant, array $owner, ?Actor $actor = null): ProvisionResult
    {
        $slug = Str::lower(trim($tenant['slug']));
        $email = Str::lower(trim($owner['email']));
        if (! self::validSlug($slug)) {
            throw new ProvisioningError('Geçersiz tenant kodu: küçük harf, rakam ve tire; 2–63 karakter.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ProvisioningError('Geçerli bir owner e-posta adresi girin.');
        }
        $existing = $this->existing($slug, $email);
        if ($existing !== null) {
            return $existing;
        }

        $tenantId = (string) Str::uuid7();
        try {
            return $this->context->run($tenantId, fn () => DB::transaction(fn () => $this->create($tenantId, $slug, $email, $tenant, $owner, $actor)));
        } catch (QueryException $e) {
            // Another request created the slug in the meantime: same answer as a re-run.
            if (($e->errorInfo[0] ?? null) === '23505') {
                return $this->existing($slug, $email) ?? throw new ProvisioningError('Bu tenant kodu kullanılıyor.');
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $tenant
     * @param  array<string, mixed>  $owner
     */
    private function create(string $tenantId, string $slug, string $email, array $tenant, array $owner, ?Actor $actor): ProvisionResult
    {
        $model = Tenant::query()->create([
            'id' => $tenantId,
            'public_id' => Str::lower(Str::random(24)),
            'name' => trim((string) $tenant['name']),
            'slug' => $slug,
            'status' => Tenant::STATUS_ACTIVE,
            'country' => Str::upper($tenant['country']),
            'timezone' => $tenant['timezone'],
            'currency' => Str::upper($tenant['currency']),
            'locale' => $tenant['locale'],
            'contact_phone' => ($tenant['contact_phone'] ?? null) ?: null,
        ]);

        // Defaults: sending mode off, no integration (every provider "not configured").
        TenantSetting::query()->create([]);

        $password = ($owner['password'] ?? null) ?: null;
        $user = User::query()->where('email', $email)->first();
        $newUser = $user === null;
        if ($user === null) {
            $user = new User(['email' => $email, 'name' => trim((string) $owner['name']) ?: $email,
                // Unusable until the owner sets their own password through the invitation.
                'password' => $password ?? Str::password(48)]);
            $user->forceFill(['activated_at' => $password === null ? null : now()])->save();
        }
        // An operator password opens an account directly (CLI); otherwise the owner is invited.
        $direct = $password !== null;
        $membership = TenantUser::query()->create(['user_id' => $user->id, 'role' => TenantRole::Owner, 'status' => $direct ? 'active' : 'invited']);

        $this->vault->ensureDataKey();

        $this->audit->record(AuditAction::TenantCreated, 'tenant', $tenantId, ['slug' => $model->slug, 'country' => $model->country, 'owner_account' => $newUser ? 'new' : 'existing'], $actor);
        $invitation = $direct ? null : $this->invitations->issue($membership, $actor ?? Actor::system(), AuditAction::TenantOwnerInvited);

        return new ProvisionResult($model, $user, true, $invitation);
    }

    /**
     * The tenant already provisioned under this slug for this owner, if any.
     */
    private function existing(string $slug, string $email): ?ProvisionResult
    {
        // Active tenants only (directory function); a suspended tenant's slug
        // still cannot be reused: the insert hits the unique constraint.
        $id = $this->directory->idBySlug($slug);
        if ($id === null) {
            return null;
        }

        return $this->context->run((string) $id, function () use ($email) {
            $user = User::query()->where('email', $email)->first();
            $isOwner = $user !== null && TenantUser::query()->where('user_id', $user->id)->where('role', TenantRole::Owner->value)->exists();
            if (! $isOwner) {
                throw new ProvisioningError('Bu tenant kodu başka bir müşteride kullanılıyor; farklı bir kod seçin.');
            }

            return new ProvisionResult(Tenant::query()->whereKey($this->context->requireTenant())->firstOrFail(), $user, false, null);
        });
    }
}
