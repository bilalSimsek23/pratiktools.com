<?php

namespace App\Tenancy;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Account activation / tenant invitations.
 *
 * - The token is 64 random characters; only its SHA-256 is stored. It is
 *   single use (accepted_at under a row lock), expires, and is revoked when
 *   a new link is issued for the same membership.
 * - The link is also a signed URL on the panel host (relative signature),
 *   so a tampered or expired link is refused before any lookup.
 * - A new account sets its own password through the link; nobody else ever
 *   chooses it. An existing (activated) account must sign in as itself to
 *   accept: holding the link is never enough to take over an account.
 * - Provisioning never depends on e-mail: delivery is "manual" (the Super
 *   Admin hands the link over) until transactional e-mail exists.
 */
final class TenantInvitations
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    /**
     * Issues a new link for an invited membership (current tenant context).
     */
    public function issue(TenantUser $membership, Actor $actor, AuditAction $action = AuditAction::TenantUserInvited): IssuedInvitation
    {
        $tenantId = $this->context->requireTenant();
        if (Tenant::query()->whereKey($tenantId)->value('status') !== Tenant::STATUS_ACTIVE) {
            throw new ProvisioningError('Müşteri askıda; davet oluşturulamaz.');
        }
        $now = now();
        DB::table('user_invitations')->where('tenant_id', $tenantId)->where('membership_id', $membership->id)->whereNull('accepted_at')->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);
        $token = Str::random(64);
        $expires = $now->copy()->addHours(max(1, (int) config('platform.invitations.ttl_hours')));
        $id = (string) Str::uuid7();
        DB::table('user_invitations')->insert(['id' => $id, 'tenant_id' => $tenantId, 'membership_id' => $membership->id, 'user_id' => $membership->user_id,
            'role' => $membership->role->value, 'token_hash' => self::hash($token), 'delivery' => 'manual', 'expires_at' => $expires,
            'created_by' => mb_substr($actor->type.($actor->id !== null ? ':'.$actor->id : ''), 0, 64), 'created_at' => $now]);
        $this->audit->record($action, 'tenant_user', (string) $membership->id, ['role' => $membership->role->value, 'invitation_id' => $id, 'expires_in_hours' => (int) config('platform.invitations.ttl_hours')], $actor);

        return new IssuedInvitation($id, self::link($token, $expires), $expires);
    }

    /**
     * What an open link shows, or null for any invalid, used, revoked or
     * expired link (one answer for all, no oracle).
     *
     * @return array{tenant_id: string, tenant_name: string, email: string, name: string, role: string, new_account: bool, user_id: string}|null
     */
    public function resolve(string $token): ?array
    {
        $tenantId = $this->tenantOf($token);

        return $tenantId === null ? null : $this->context->run($tenantId, function () use ($token) {
            $row = $this->open($token);
            if ($row === null) {
                return null;
            }
            $user = User::query()->whereKey($row->user_id)->first();

            return $user === null ? null : ['tenant_id' => (string) $row->tenant_id, 'tenant_name' => (string) Tenant::query()->whereKey($row->tenant_id)->value('name'),
                'email' => $user->email, 'name' => $user->name, 'role' => (string) $row->role, 'new_account' => $user->activated_at === null, 'user_id' => $user->id];
        });
    }

    /**
     * Accepts a link: activates the membership and, for a new account, sets
     * the password chosen by its owner. $signedIn is the panel user of this
     * session (required for an existing account).
     *
     * @return array{tenant_id: string, user: User}
     */
    public function accept(string $token, ?string $password, ?User $signedIn): array
    {
        $tenantId = $this->tenantOf($token) ?? throw new ProvisioningError('invalid');

        return $this->context->run($tenantId, fn () => DB::transaction(function () use ($token, $password, $signedIn, $tenantId) {
            $row = $this->open($token, lock: true) ?? throw new ProvisioningError('invalid');
            $user = User::query()->whereKey($row->user_id)->lockForUpdate()->firstOrFail();
            if ($user->activated_at === null) {
                if ($password === null || strlen($password) < 12) {
                    throw new ProvisioningError('password');
                }
                $user->forceFill(['password' => $password, 'activated_at' => now(),
                    // Only a link delivered by e-mail proves the address.
                    'email_verified_at' => $row->delivery === 'email' ? now() : $user->email_verified_at])->save();
            } elseif ($signedIn?->id !== $user->id) {
                throw new ProvisioningError('sign_in');
            }
            TenantUser::query()->whereKey($row->membership_id)->update(['status' => 'active']);
            DB::table('user_invitations')->where('id', $row->id)->update(['accepted_at' => now()]);
            $this->audit->record(AuditAction::TenantInvitationAccepted, 'tenant_user', (string) $row->membership_id, ['invitation_id' => (string) $row->id], Actor::user($user->id));

            return ['tenant_id' => $tenantId, 'user' => $user];
        }));
    }

    private function tenantOf(string $token): ?string
    {
        if (preg_match('/^[A-Za-z0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $id = DB::selectOne('SELECT app_invitation_tenant(?) AS id', [self::hash($token)])?->id;

        return $id === null ? null : (string) $id;
    }

    /**
     * The invitation row if it can still be used (current tenant context).
     */
    private function open(string $token, bool $lock = false): ?object
    {
        $q = DB::table('user_invitations as i')->join('tenant_users as m', fn ($j) => $j->on('m.id', '=', 'i.membership_id')->on('m.tenant_id', '=', 'i.tenant_id'))
            ->where('i.token_hash', self::hash($token))->whereNull('i.accepted_at')->whereNull('i.revoked_at')->where('i.expires_at', '>', now())
            ->where('m.status', 'invited')->select(['i.id', 'i.tenant_id', 'i.membership_id', 'i.user_id', 'i.role', 'i.delivery']);
        $row = ($lock ? $q->lockForUpdate() : $q)->first();
        if ($row === null || Tenant::query()->whereKey($row->tenant_id)->value('status') !== Tenant::STATUS_ACTIVE) {
            return null;
        }

        return $row;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function link(string $token, Carbon $expires): string
    {
        return rtrim((string) config('app.url'), '/').URL::temporarySignedRoute('panel.invitation', $expires, ['token' => $token], absolute: false);
    }
}
