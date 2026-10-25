<?php

namespace App\Notifications;

use App\Tenancy\Permission;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * In-app notification inbox (no e-mail, no push, no provider). A
 * notification targets one user or everyone holding a permission.
 */
final class TenantNotifications
{
    public function __construct(private readonly TenantContext $context) {}

    public function notify(Permission $permission, string $kind, string $subjectType, string $subjectId, string $message, ?string $userId = null): void
    {
        DB::table('tenant_notifications')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->context->requireTenant(), 'user_id' => $userId,
            'permission' => $permission->value, 'kind' => $kind, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'message' => mb_substr($message, 0, 300), 'created_at' => now()]);
    }

    /**
     * @return Collection<int, \stdClass>
     */
    public function forMember(string $userId, TenantRole $role, bool $unreadOnly = false, int $limit = 50): Collection
    {
        $permissions = array_map(fn (Permission $p) => $p->value, $role->permissions());

        return DB::table('tenant_notifications')->where('tenant_id', $this->context->requireTenant())
            ->where(fn ($q) => $q->where('user_id', $userId)->orWhere(fn ($q2) => $q2->whereNull('user_id')->whereIn('permission', $permissions)))
            ->when($unreadOnly, fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('created_at')->limit($limit)->get();
    }

    public function markRead(string $id): void
    {
        DB::table('tenant_notifications')->where('tenant_id', $this->context->requireTenant())->where('id', $id)->whereNull('read_at')->update(['read_at' => now()]);
    }
}
