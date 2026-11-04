<?php

namespace App\Platform;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Models\PlatformAdmin;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

/**
 * Super Admin activation links, web side (platform role only). Accounts are
 * invited by platform:admin:invite (console, owner connection): the token
 * is 64 random characters, only its SHA-256 is stored, and the link is a
 * signed URL on the admin host that expires with the token.
 *
 * activate() hashes the password chosen in the browser and stores it
 * through app_platform_admin_activate() (SECURITY DEFINER), which only
 * changes an invited, unexpired account with that token and clears the
 * token. The platform role has no UPDATE on password or status.
 */
final class PlatformAdminActivations
{
    public const MIN_TTL_MINUTES = 5;

    public const MAX_TTL_MINUTES = 120;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The invited account an open link belongs to, or null for any invalid,
     * used, replaced or expired link (one answer for all).
     */
    public function resolve(string $token): ?PlatformAdmin
    {
        if (! self::wellFormed($token)) {
            return null;
        }

        return PlatformAdmin::query()->where('activation_token_hash', self::hash($token))->where('status', PlatformAdmin::STATUS_INVITED)
            ->where('activation_expires_at', '>', now())->first();
    }

    /**
     * Sets the password chosen by the account's owner and activates it.
     * Null when the link can no longer be used.
     */
    public function activate(string $token, #[\SensitiveParameter] string $password): ?PlatformAdmin
    {
        if (! self::wellFormed($token)) {
            return null;
        }
        $id = DB::connection('pgsql_platform')->selectOne('SELECT app_platform_admin_activate(?, ?) AS id', [self::hash($token), Hash::make($password)])?->id;
        if ($id === null) {
            return null;
        }
        $this->audit->record(AuditAction::PlatformAdminActivated, 'platform_admin', (string) $id, [], Actor::platformAdmin((string) $id),
            tenantId: false, connection: 'pgsql_platform');

        return PlatformAdmin::query()->find($id);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function link(string $token, CarbonInterface $expires): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return $scheme.'://'.config('platform.admin_domain').URL::temporarySignedRoute('admin.activate', $expires, ['token' => $token], absolute: false);
    }

    private static function wellFormed(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1;
    }
}
