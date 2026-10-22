<?php

namespace App\Audit;

use App\Logging\Redactor;
use App\Models\AuditLog;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

/**
 * Writes append-only audit entries. Metadata passes through the redactor,
 * and IP address / user agent are stored only as keyed HMACs.
 */
final class AuditLogger
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Redactor $redactor,
        private readonly ?Request $request = null,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @param  string|false|null  $tenantId  null: current tenant; false: platform-level entry
     */
    public function record(
        AuditAction $action,
        ?string $resourceType = null,
        ?string $resourceId = null,
        array $metadata = [],
        ?Actor $actor = null,
        string|false|null $tenantId = null,
        string $connection = 'pgsql',
    ): AuditLog {
        $actor ??= $this->resolveActor();
        $tenant = $tenantId === false ? null : ($tenantId ?? $this->context->tenantId());

        $entry = new AuditLog;
        $entry->setConnection($connection);
        $entry->fill([
            'tenant_id' => $tenant,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'action' => $action->value,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'metadata' => $this->redactor->redact($metadata),
            'ip_hash' => $this->hash($this->request?->ip()),
            'user_agent_hash' => $this->hash($this->request?->userAgent()),
            'request_id' => Context::get('request_id'),
        ]);
        $entry->save();

        return $entry;
    }

    private function resolveActor(): Actor
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return Actor::console();
        }

        $admin = Auth::guard('platform')->user();
        if ($admin instanceof PlatformAdmin) {
            return Actor::platformAdmin($admin->id);
        }

        $user = $this->request?->user();
        if ($user instanceof User) {
            return Actor::user($user->id);
        }

        return Actor::system();
    }

    private function hash(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return hash_hmac('sha256', $value, (string) config('platform.audit.hash_key'));
    }
}
