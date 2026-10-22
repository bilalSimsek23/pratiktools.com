<?php

namespace App\Tenancy;

enum TenantRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Marketer = 'marketer';
    case Viewer = 'viewer';

    public function allows(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Admin => array_values(array_filter(
                Permission::cases(),
                fn (Permission $p) => $p !== Permission::SendingModeUpdate,
            )),
            self::Marketer => [
                Permission::TenantView,
                Permission::MembersView,
                Permission::IntegrationsView,
                Permission::CustomersView,
            ],
            self::Viewer => [
                Permission::TenantView,
                Permission::CustomersView,
            ],
        };
    }
}
