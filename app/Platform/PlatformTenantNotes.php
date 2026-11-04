<?php

namespace App\Platform;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Tenancy\ProvisioningError;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin-only notes about a tenant (platform_tenant_notes). Read and
 * written only through the platform connection: the runtime role has no
 * grant on the table, so no tenant request, API or model can reach it.
 * Never a place for secrets; values that look like one are refused. The
 * audit records that the note changed, not its text.
 */
final class PlatformTenantNotes
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function get(string $tenantId): string
    {
        return (string) DB::connection('pgsql_platform')->table('platform_tenant_notes')->where('tenant_id', $tenantId)->value('note');
    }

    public function set(string $tenantId, string $note, string $adminId): void
    {
        $note = trim(mb_substr(str_replace("\r\n", "\n", $note), 0, 1000));
        // A long unbroken token (API key, password hash, secret) does not belong in a note.
        if (preg_match('/[A-Za-z0-9+\/=_\-]{32,}/', $note) === 1 || preg_match('/\b(password|parola|şifre|secret|api[_ -]?key|token)\s*[:=]/iu', $note) === 1) {
            throw new ProvisioningError('Not gizli bir değer (şifre, anahtar, token) içeriyor gibi görünüyor; gizli değerler notlarda saklanmaz.');
        }
        if ($note === $this->get($tenantId)) {
            return;
        }
        DB::connection('pgsql_platform')->table('platform_tenant_notes')->upsert([['tenant_id' => $tenantId, 'note' => $note, 'updated_by' => $adminId, 'updated_at' => now()]],
            ['tenant_id'], ['note', 'updated_by', 'updated_at']);
        $this->audit->record(AuditAction::PlatformTenantNoteChanged, 'tenant', $tenantId, ['length' => mb_strlen($note), 'cleared' => $note === ''],
            Actor::platformAdmin($adminId), tenantId: $tenantId, connection: 'pgsql_platform');
    }
}
