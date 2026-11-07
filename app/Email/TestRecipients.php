<?php

namespace App\Email;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The current tenant's e-mail test allowlist (email:test-recipient and
 * Super Admin): the only addresses reachable in test_only mode and the
 * only ones a "test" message can ever go to. The audit trail names an
 * address by a hash, never in clear.
 */
final class TestRecipients
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Rows of email, label, approved_by, active, updated_at.
     *
     * @return Collection<int, \stdClass>
     */
    public function list(bool $activeOnly = false): Collection
    {
        $this->context->requireTenant();

        return DB::table('test_recipients')->where('channel', 'email')->where('provider', '*')->when($activeOnly, fn ($q) => $q->where('active', true))
            ->orderByDesc('active')->orderBy('provider_recipient_id')
            ->get(['provider_recipient_id as email', 'label', 'approved_by', 'active', 'updated_at']);
    }

    /**
     * @throws InvalidEmailMessage INVALID_RECIPIENT, LABEL_REQUIRED, APPROVER_REQUIRED
     */
    public function add(string $email, string $label, string $approvedBy, Actor $actor): void
    {
        $tenantId = $this->context->requireTenant();
        $email = MessageRules::address($email, 'INVALID_RECIPIENT');
        $label = trim($label);
        $approvedBy = trim($approvedBy);
        if ($label === '') {
            throw new InvalidEmailMessage('LABEL_REQUIRED', 'Say whose address this is.');
        }
        if ($approvedBy === '') {
            throw new InvalidEmailMessage('APPROVER_REQUIRED', 'Name the person approving this address.');
        }

        DB::table('test_recipients')->upsert([[
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'channel' => 'email', 'provider' => '*', 'provider_recipient_id' => $email,
            'label' => mb_substr($label, 0, 120), 'approved_by' => mb_substr($approvedBy, 0, 64),
            'active' => true, 'deactivated_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]], ['tenant_id', 'channel', 'provider', 'provider_recipient_id'], ['label', 'approved_by', 'active', 'deactivated_at', 'updated_at']);
        $this->audit->record(AuditAction::EmailTestRecipientAdded, 'test_recipient', self::ref($email), ['label' => mb_substr($label, 0, 120), 'approved_by' => mb_substr($approvedBy, 0, 64)], $actor);
    }

    /**
     * @throws InvalidEmailMessage INVALID_RECIPIENT
     */
    public function remove(string $email, Actor $actor): bool
    {
        $this->context->requireTenant();
        $email = MessageRules::address($email, 'INVALID_RECIPIENT');
        $changed = DB::table('test_recipients')->where('channel', 'email')->where('provider', '*')->where('provider_recipient_id', $email)->where('active', true)
            ->update(['active' => false, 'deactivated_at' => now(), 'updated_at' => now()]);
        $this->audit->record(AuditAction::EmailTestRecipientRemoved, 'test_recipient', self::ref($email), [], $actor);

        return $changed > 0;
    }

    private static function ref(string $email): string
    {
        return substr(hash('sha256', $email), 0, 16);
    }
}
