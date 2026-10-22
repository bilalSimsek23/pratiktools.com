<?php

namespace App\Tenancy;

use App\Models\TenantUser;
use App\Tenancy\Exceptions\TenantContextMissing;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The single source of the current tenant for HTTP requests, queued jobs,
 * scheduled tasks, webhook processing and console commands.
 *
 * The context is mirrored into the PostgreSQL session (app.tenant_id,
 * app.user_id, app.context) on every tenant connection, so row-level
 * security enforces the same boundary as the application. It is applied
 * again whenever a connection is (re)established.
 *
 * The tenant is only ever set from trusted sources: the tenant bound to an
 * authenticated API token, the tenant id a job was dispatched with, a
 * console operator's --tenant option, or a webhook URL resolved through the
 * tenant directory. Never from request parameters.
 */
final class TenantContext
{
    public const MODE_NONE = 'none';

    public const MODE_TENANT = 'tenant';

    public const MODE_SYSTEM = 'system';

    private ?string $tenantId = null;

    private ?string $userId = null;

    private string $mode = self::MODE_NONE;

    private ?TenantUser $membership = null;

    public function __construct(private readonly DatabaseManager $db) {}

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    /**
     * @throws TenantContextMissing
     */
    public function requireTenant(): string
    {
        return $this->tenantId ?? throw new TenantContextMissing;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function isSystem(): bool
    {
        return $this->mode === self::MODE_SYSTEM;
    }

    public function membership(): ?TenantUser
    {
        return $this->membership;
    }

    public function setTenant(string $tenantId): void
    {
        if (! Str::isUuid($tenantId)) {
            throw new InvalidArgumentException('Tenant id must be a UUID.');
        }

        $this->tenantId = strtolower($tenantId);
        $this->mode = self::MODE_TENANT;
        $this->membership = null;
        $this->apply();
    }

    public function setUser(?string $userId): void
    {
        if ($userId !== null && ! Str::isUuid($userId)) {
            throw new InvalidArgumentException('User id must be a UUID.');
        }

        $this->userId = $userId;
        $this->apply();
    }

    public function setMembership(TenantUser $membership): void
    {
        if ($membership->tenant_id !== $this->tenantId) {
            throw new InvalidArgumentException('Membership does not belong to the current tenant.');
        }

        $this->membership = $membership;
    }

    /**
     * Platform-level processing that must touch tenant-less rows (for example
     * webhooks that are not yet attributed to a tenant). Never grants access
     * to any tenant's rows.
     */
    public function enterSystem(): void
    {
        $this->tenantId = null;
        $this->membership = null;
        $this->mode = self::MODE_SYSTEM;
        $this->apply();
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->userId = null;
        $this->membership = null;
        $this->mode = self::MODE_NONE;
        $this->apply();
    }

    /**
     * Run a callback inside a tenant context and restore the previous context
     * afterwards, even when the callback throws.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(string $tenantId, Closure $callback): mixed
    {
        $previous = $this->snapshot();

        try {
            $this->setTenant($tenantId);

            return $callback();
        } finally {
            $this->restore($previous);
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAsSystem(Closure $callback): mixed
    {
        $previous = $this->snapshot();

        try {
            $this->enterSystem();

            return $callback();
        } finally {
            $this->restore($previous);
        }
    }

    /**
     * @return array{tenant: ?string, user: ?string, mode: string, membership: ?TenantUser}
     */
    public function snapshot(): array
    {
        return [
            'tenant' => $this->tenantId,
            'user' => $this->userId,
            'mode' => $this->mode,
            'membership' => $this->membership,
        ];
    }

    /**
     * @param  array{tenant: ?string, user: ?string, mode: string, membership: ?TenantUser}  $snapshot
     */
    public function restore(array $snapshot): void
    {
        $this->tenantId = $snapshot['tenant'];
        $this->userId = $snapshot['user'];
        $this->mode = $snapshot['mode'];
        $this->membership = $snapshot['membership'];
        $this->apply();
    }

    /**
     * Push the context into the PostgreSQL session of every tenant connection
     * that is already open. Connections opened later receive it from the
     * ConnectionEstablished listener.
     */
    public function apply(): void
    {
        Context::add('tenant_id', $this->tenantId);

        foreach ($this->db->getConnections() as $name => $connection) {
            if (in_array($name, self::tenantConnections(), true)) {
                $this->applyTo($connection);
            }
        }
    }

    public function applyTo(Connection $connection): void
    {
        $connection->select(
            "SELECT set_config('app.tenant_id', ?, false), set_config('app.user_id', ?, false), set_config('app.context', ?, false)",
            [$this->tenantId ?? '', $this->userId ?? '', $this->mode],
        );
    }

    /**
     * @return list<string>
     */
    public static function tenantConnections(): array
    {
        return ['pgsql'];
    }
}
