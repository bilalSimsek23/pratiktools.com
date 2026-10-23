<?php

namespace App\Consent;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Local suppressions. An active suppression always blocks sending. Lifting
 * one is an explicit, audited act with a reason; rows are never deleted.
 */
final class SuppressionService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function suppress(string $recipientId, SuppressionSource $source, ?ConsentChannel $channel, ?string $reason, Actor $actor): string
    {
        $tenantId = $this->context->requireTenant();
        $existing = DB::table('suppressions')->where('tenant_id', $tenantId)->where('recipient_id', $recipientId)
            ->where('source', $source->value)->whereNull('lifted_at')
            ->where(fn ($q) => $channel === null ? $q->whereNull('channel') : $q->where('channel', $channel->value))
            ->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid7();
        DB::transaction(function () use ($id, $tenantId, $recipientId, $source, $channel, $reason, $actor) {
            DB::table('suppressions')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'recipient_id' => $recipientId,
                'channel' => $channel?->value,
                'source' => $source->value,
                'reason' => $reason === null ? null : mb_substr($reason, 0, 255),
                'created_by' => $actor->type.($actor->id !== null ? ':'.$actor->id : ''),
                'created_at' => now(),
            ]);
            $this->audit->record(AuditAction::SuppressionCreated, 'suppression', $id, ['source' => $source->value, 'channel' => $channel?->value], $actor);
        });

        return $id;
    }

    public function lift(string $suppressionId, string $reason, Actor $actor): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Lifting a suppression needs a reason.');
        }

        DB::transaction(function () use ($suppressionId, $reason, $actor) {
            $updated = DB::table('suppressions')
                ->where('tenant_id', $this->context->requireTenant())
                ->where('id', $suppressionId)
                ->whereNull('lifted_at')
                ->update(['lifted_at' => now(), 'lifted_by' => $actor->type.($actor->id !== null ? ':'.$actor->id : ''), 'lift_reason' => mb_substr($reason, 0, 255)]);
            if ($updated === 0) {
                throw new InvalidArgumentException('No active suppression with this id.');
            }
            $this->audit->record(AuditAction::SuppressionLifted, 'suppression', $suppressionId, ['reason' => mb_substr($reason, 0, 255)], $actor);
        });
    }
}
