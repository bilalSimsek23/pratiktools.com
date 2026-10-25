<?php

namespace App\Operations;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Tenant sending mode from the panel/API: only OFF ("off") or TEST_ONLY
 * ("internal"). Live is refused and the attempt is audited. The platform
 * switches (EMAIL_SENDING_MODE, PUSH_SENDING_MODE) are environment
 * settings and cannot be changed from the application at all.
 */
final class SendingModes
{
    public const ALLOWED = ['off', 'internal'];

    public const LIVE_DISABLED_REASON = 'Live sending is disabled until external readiness requirements are satisfied.';

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function set(string $mode, Actor $actor): void
    {
        $tenantId = $this->context->requireTenant();
        if (! in_array($mode, self::ALLOWED, true)) {
            $this->audit->record(AuditAction::SendingModeChangeRefused, 'tenant', $tenantId, ['requested' => mb_substr($mode, 0, 16)], $actor);
            throw new InvalidArgumentException(self::LIVE_DISABLED_REASON);
        }
        $previous = DB::table('tenant_settings')->where('tenant_id', $tenantId)->value('sending_mode');
        DB::table('tenant_settings')->where('tenant_id', $tenantId)->update(['sending_mode' => $mode, 'updated_at' => now()]);
        $this->audit->record(AuditAction::SendingModeChanged, 'tenant', $tenantId, ['from' => $previous, 'to' => $mode], $actor);
    }
}
