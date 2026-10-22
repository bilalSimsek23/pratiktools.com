<?php

namespace App\Platform;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Models\BreakGlassGrant;
use App\Models\PlatformAdmin;

/**
 * Records a time-boxed emergency access grant of a Super Admin to a
 * tenant. Always audited on the tenant's trail. In F0 the grant is only
 * recorded; no data access path honours it yet.
 */
final class BreakGlass
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function grant(PlatformAdmin $admin, string $tenantId, string $reason, int $minutes = 60): BreakGlassGrant
    {
        $minutes = max(5, min($minutes, 240));

        $grant = BreakGlassGrant::query()->create([
            'platform_admin_id' => $admin->id,
            'tenant_id' => $tenantId,
            'reason' => mb_substr($reason, 0, 500),
            'expires_at' => now()->addMinutes($minutes),
        ]);

        $this->audit->record(
            AuditAction::BreakGlassGranted,
            'break_glass_grant',
            $grant->id,
            ['minutes' => $minutes, 'reason' => $grant->reason],
            Actor::platformAdmin($admin->id),
            tenantId: $tenantId,
            connection: 'pgsql_platform',
        );

        return $grant;
    }
}
