<?php

namespace App\Push;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Commerce\Identity\ContactNormalizer;
use App\Commerce\Identity\CustomerIdentityService as Identities;
use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Links push profiles to customers through trusted identifiers only:
 *
 * 1. push_identity_mappings (ids we issued, legacy formats, manual);
 * 2. the integration's external_id_strategy:
 *    - ticimax_member_id: a numeric external id is a Ticimax member id
 *      (customer_identities commerce_member, unique per tenant);
 *    - email: only if explicitly configured; never marketplace relay
 *      addresses, never an address with an open identity conflict;
 *    - none (default): nothing beyond mappings.
 * 3. otherwise unresolved. Phone numbers are never used, customers are
 *    never merged or created here.
 */
final class PushIdentityResolver
{
    public const PLATFORM_PREFIX = 'ptc_';

    public function __construct(
        private readonly TenantContext $context,
        private readonly Identities $identities,
        private readonly ContactNormalizer $normalizer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, int> identity status => profiles
     */
    public function resolveAll(TenantIntegration $integration, Actor $actor): array
    {
        $tenantId = $this->context->requireTenant();
        $strategy = (string) ($integration->settings['external_id_strategy'] ?? 'none');
        $changed = 0;

        DB::table('push_profiles')->where('tenant_id', $tenantId)->where('integration_id', $integration->id)->whereNotNull('external_id')
            ->orderBy('id')->chunkById(1000, function ($profiles) use ($strategy, &$changed) {
                foreach ($profiles as $p) {
                    [$customerId, $status, $source] = $this->resolve((string) $p->external_id, $strategy);
                    if ($customerId !== $p->customer_id || $status !== $p->identity_status) {
                        DB::table('push_profiles')->where('tenant_id', $p->tenant_id)->where('id', $p->id)
                            ->update(['customer_id' => $customerId, 'identity_status' => $status, 'identity_source' => $source, 'updated_at' => now()]);
                        $changed += (int) ($customerId !== $p->customer_id);
                    }
                }
            });

        $counts = DB::table('push_profiles')->where('tenant_id', $tenantId)->where('integration_id', $integration->id)
            ->selectRaw('identity_status, count(*) AS n')->groupBy('identity_status')->pluck('n', 'identity_status')->map(fn ($n) => (int) $n)->all();

        if ($changed > 0) {
            $this->audit->record(AuditAction::PushIdentityMappingChanged, 'tenant_integration', $integration->id, ['changed_profiles' => $changed, 'strategy' => $strategy], $actor);
        }

        return $counts;
    }

    /**
     * @return array{0: ?string, 1: string, 2: ?string} customer id, identity status, source
     */
    public function resolve(string $externalId, string $strategy): array
    {
        $tenantId = $this->context->requireTenant();
        $mapped = DB::table('push_identity_mappings')->where('tenant_id', $tenantId)->where('provider', 'onesignal')->where('external_id', $externalId)->first(['customer_id', 'source']);
        if ($mapped !== null) {
            return [(string) $mapped->customer_id, 'resolved', 'mapping:'.$mapped->source];
        }

        if ($strategy === 'ticimax_member_id' && preg_match('/^[1-9]\d{0,18}$/', $externalId)) {
            $customerId = $this->identities->find(Identities::MEMBER, $externalId);

            return $customerId !== null ? [$customerId, 'resolved', 'commerce_member'] : [null, 'unresolved', null];
        }

        if ($strategy === 'email') {
            $email = $this->normalizer->email($externalId);
            if ($email === null || $this->normalizer->isMarketplaceEmail($email)) {
                return [null, 'unresolved', null];
            }
            $customerId = $this->identities->find(Identities::EMAIL, $email);
            if ($customerId === null) {
                return [null, 'unresolved', null];
            }
            // Shared addresses: another customer claims the same e-mail.
            $conflict = DB::table('customer_merges')->where('tenant_id', $tenantId)->where('identity_type', Identities::EMAIL)
                ->where('identity_hash', hash('sha256', $email))->where('status', 'pending_review')->exists();

            return $conflict ? [null, 'conflict', 'email'] : [$customerId, 'resolved', 'email'];
        }

        return [null, 'unresolved', null];
    }

    /**
     * The external id to give a customer when the mobile app logs in:
     * tenant-scoped, opaque, random, never the database id.
     */
    public function platformExternalId(string $customerId, Actor $actor): string
    {
        $tenantId = $this->context->requireTenant();
        $existing = DB::table('push_identity_mappings')->where('tenant_id', $tenantId)->where('provider', 'onesignal')
            ->where('customer_id', $customerId)->where('source', 'platform')->value('external_id');
        if (is_string($existing)) {
            return $existing;
        }

        $externalId = self::PLATFORM_PREFIX.Str::lower(Str::random(24));
        DB::table('push_identity_mappings')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'provider' => 'onesignal', 'external_id' => $externalId,
            'customer_id' => $customerId, 'source' => 'platform', 'created_by' => $actor->type, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record(AuditAction::PushIdentityMappingChanged, 'customer', $customerId, ['source' => 'platform'], $actor);

        return $externalId;
    }
}
