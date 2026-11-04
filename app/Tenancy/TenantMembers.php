<?php

namespace App\Tenancy;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Membership changes for one tenant, from a Super Admin (Platform Yönetimi)
 * or from a tenant's own owner/admin (/app › Ekip, the *AsMember methods).
 * Every call runs inside that tenant's context on the runtime connection,
 * so RLS applies; roles are the existing TenantRole set.
 *
 * - A tenant always keeps at least one active owner: the last one cannot be
 *   disabled or demoted (there is no membership delete).
 * - From the panel, owner gives admin/marketer/approver/viewer and admin
 *   gives marketer/approver/viewer; nobody creates an owner, changes an
 *   owner or changes themselves there. Ownership transfer is a Super Admin
 *   action (extension point for a self-service transfer).
 * - The tenant of a panel call is the actor's own membership, never input.
 */
final class TenantMembers
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly TenantInvitations $invitations,
    ) {}

    /**
     * Roles a member may give from the panel.
     *
     * @return list<TenantRole>
     */
    public static function assignableBy(TenantRole $actor): array
    {
        return match ($actor) {
            TenantRole::Owner => [TenantRole::Admin, TenantRole::Marketer, TenantRole::Approver, TenantRole::Viewer],
            TenantRole::Admin => [TenantRole::Marketer, TenantRole::Approver, TenantRole::Viewer],
            default => [],
        };
    }

    public function inviteAsMember(TenantUser $actor, string $email, string $name, TenantRole $role): IssuedInvitation
    {
        $this->allow($actor, $role);

        return $this->invite($actor->tenant_id, $email, $name, $role, Actor::user($actor->user_id));
    }

    public function reissueAsMember(TenantUser $actor, string $membershipId): IssuedInvitation
    {
        $this->allowTarget($actor, $membershipId);

        return $this->reissue($actor->tenant_id, $membershipId, Actor::user($actor->user_id));
    }

    public function changeRoleAsMember(TenantUser $actor, string $membershipId, TenantRole $role): void
    {
        $this->allowTarget($actor, $membershipId);
        $this->allow($actor, $role);
        $this->changeRole($actor->tenant_id, $membershipId, $role, Actor::user($actor->user_id));
    }

    public function setStatusAsMember(TenantUser $actor, string $membershipId, string $status): void
    {
        $this->allowTarget($actor, $membershipId);
        $this->setStatus($actor->tenant_id, $membershipId, $status, Actor::user($actor->user_id));
    }

    private function allow(TenantUser $actor, TenantRole $role): void
    {
        if (! in_array($role, self::assignableBy($actor->role), true)) {
            throw new ProvisioningError('Bu rolü veremezsiniz.');
        }
    }

    private function allowTarget(TenantUser $actor, string $membershipId): void
    {
        $target = $this->context->run($actor->tenant_id, fn () => Str::isUuid($membershipId) ? TenantUser::query()->whereKey($membershipId)->first() : null)
            ?? throw new ProvisioningError('Üyelik bulunamadı.');
        if ($target->id === $actor->id) {
            throw new ProvisioningError('Kendi üyeliğinizi buradan değiştiremezsiniz.');
        }
        if (! in_array($target->role, self::assignableBy($actor->role), true)) {
            throw new ProvisioningError('Bu kullanıcıyı yönetme yetkiniz yok.');
        }
    }

    /**
     * Invites a person (new or existing account) with a role.
     */
    public function invite(string $tenantId, string $email, string $name, TenantRole $role, Actor $actor): IssuedInvitation
    {
        $email = Str::lower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ProvisioningError('Geçerli bir e-posta adresi girin.');
        }

        return $this->context->run($tenantId, fn () => DB::transaction(function () use ($email, $name, $role, $actor) {
            $user = User::query()->where('email', $email)->first();
            if ($user === null) {
                $user = new User(['email' => $email, 'name' => trim($name) ?: $email, 'password' => Str::password(48)]);
                $user->forceFill(['activated_at' => null])->save();
            }
            $existing = TenantUser::query()->where('user_id', $user->id)->first();
            if ($existing !== null) {
                throw new ProvisioningError(match ($existing->status) {
                    'invited' => 'Bu kişinin daveti bekliyor; gerekirse yeni bağlantı oluşturun.',
                    'disabled' => 'Bu kişi üye ama devre dışı; önce üyeliği etkinleştirin.',
                    default => 'Bu kişi zaten bu müşterinin üyesi.',
                });
            }
            $membership = TenantUser::query()->create(['user_id' => $user->id, 'role' => $role, 'status' => 'invited']);

            return $this->invitations->issue($membership, $actor, $role === TenantRole::Owner ? AuditAction::TenantOwnerInvited : AuditAction::TenantUserInvited);
        }));
    }

    /**
     * A new link for a pending invitation (the previous link stops working).
     */
    public function reissue(string $tenantId, string $membershipId, Actor $actor): IssuedInvitation
    {
        return $this->context->run($tenantId, fn () => DB::transaction(function () use ($membershipId, $actor) {
            $m = $this->find($membershipId);
            if ($m->status !== 'invited') {
                throw new ProvisioningError('Yalnız daveti bekleyen üyeler için yeni bağlantı oluşturulabilir.');
            }

            return $this->invitations->issue($m, $actor, $m->role === TenantRole::Owner ? AuditAction::TenantOwnerInvited : AuditAction::TenantUserInvited);
        }));
    }

    public function changeRole(string $tenantId, string $membershipId, TenantRole $role, Actor $actor): void
    {
        $this->context->run($tenantId, fn () => DB::transaction(function () use ($membershipId, $role, $actor) {
            $m = $this->find($membershipId);
            $from = $m->role;
            if ($from === $role) {
                return;
            }
            if ($from === TenantRole::Owner && $m->status === 'active') {
                $this->keepAnOwner($m);
            }
            $m->update(['role' => $role]);
            DB::table('user_invitations')->where('membership_id', $m->id)->whereNull('accepted_at')->whereNull('revoked_at')->update(['role' => $role->value]);
            $this->audit->record(AuditAction::TenantRoleChanged, 'tenant_user', $m->id, ['from' => $from->value, 'to' => $role->value], $actor);
        }));
    }

    /**
     * @param  string  $status  disabled | active
     */
    public function setStatus(string $tenantId, string $membershipId, string $status, Actor $actor): void
    {
        $this->context->run($tenantId, fn () => DB::transaction(function () use ($membershipId, $status, $actor) {
            $m = $this->find($membershipId);
            if ($status === 'disabled') {
                if ($m->status === 'disabled') {
                    return;
                }
                if ($m->role === TenantRole::Owner && $m->status === 'active') {
                    $this->keepAnOwner($m);
                }
                $m->update(['status' => 'disabled']);
                DB::table('user_invitations')->where('membership_id', $m->id)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
                $this->audit->record(AuditAction::TenantMembershipDisabled, 'tenant_user', $m->id, ['role' => $m->role->value], $actor);

                return;
            }
            if ($m->status !== 'disabled') {
                return;
            }
            // Never activates an invitation nobody accepted.
            $invitations = DB::table('user_invitations')->where('membership_id', $m->id);
            $accepted = (clone $invitations)->whereNotNull('accepted_at')->exists() || ! $invitations->exists();
            $m->update(['status' => $accepted ? 'active' : 'invited']);
            $this->audit->record(AuditAction::TenantMembershipEnabled, 'tenant_user', $m->id, ['role' => $m->role->value, 'status' => $m->status], $actor);
        }));
    }

    private function find(string $membershipId): TenantUser
    {
        return (Str::isUuid($membershipId) ? TenantUser::query()->whereKey($membershipId)->lockForUpdate()->first() : null)
            ?? throw new ProvisioningError('Üyelik bulunamadı.');
    }

    private function keepAnOwner(TenantUser $m): void
    {
        $others = TenantUser::query()->where('role', TenantRole::Owner->value)->where('status', 'active')->whereKeyNot($m->id)->count();
        if ($others === 0) {
            throw new ProvisioningError('Müşterinin en az bir aktif owner\'ı kalmalı; önce başka bir owner atayın.');
        }
    }
}
