<?php

namespace App\Email;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Consent\ConsentChannel;
use App\Consent\ConsentSourceProvider;
use App\Consent\ConsentStatus;
use App\Consent\ConsentStore;
use App\Consent\ConsentStrategy;
use App\Consent\ConsentStrategyMode;
use App\Consent\RecipientType;
use App\Consent\SuppressionService;
use App\Consent\SuppressionSource;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applies an unsubscribe, local first and without waiting for anyone:
 * local suppression (UNSUBSCRIBE, marketing) and a REVOKED observation in
 * the Consent Engine (the recipient's explicit change, local RET) block
 * marketing immediately. Then an İYS RET intent is recorded for the
 * tenant's route (direct_api: İYS API; via_reconciliation: ViA Ret, with
 * a correlation reference). It stays pending: no İYS or ViA write is
 * enabled, and whatever happens to it later the local block stays.
 *
 * Replay safe: each token is applied once (email_unsubscribes); repeating
 * the request returns "already" and changes nothing.
 */
final class Unsubscribes
{
    public function __construct(
        private readonly UnsubscribeTokens $tokens,
        private readonly TenantContext $context,
        private readonly SuppressionService $suppressions,
        private readonly ConsentStore $consents,
        private readonly AuditLogger $audit,
        private readonly ConsentStrategy $strategy,
    ) {}

    /**
     * @return 'unsubscribed'|'already'
     */
    public function apply(string $token, string $method): string
    {
        $data = $this->tokens->verify($token);

        return $this->context->run($data['tenant_id'], function () use ($data, $token, $method) {
            $recipient = DB::table('consent_recipients')->where('tenant_id', $data['tenant_id'])->where('id', $data['recipient_id'])->where('address_type', 'email')->value('id')
                ?? throw new InvalidUnsubscribeToken('unknown_recipient');
            $tokenHash = hash('sha256', $token);

            return DB::transaction(function () use ($data, $recipient, $tokenHash, $method) {
                $id = (string) Str::uuid7();
                $inserted = DB::affectingStatement(
                    'INSERT INTO email_unsubscribes (id, tenant_id, recipient_id, token_hash, method, created_at) VALUES (?, ?, ?, ?, ?, now()) ON CONFLICT (tenant_id, token_hash) DO NOTHING',
                    [$id, $data['tenant_id'], $recipient, $tokenHash, $method],
                );
                if ($inserted === 0) {
                    return 'already';
                }

                $actor = Actor::system('unsubscribe');
                $suppressionId = $this->suppressions->suppress((string) $recipient, SuppressionSource::Unsubscribe, ConsentChannel::Email, "unsubscribe ({$method})", $actor);
                DB::table('email_unsubscribes')->where('id', $id)->update(['suppression_id' => $suppressionId]);

                $now = CarbonImmutable::now();
                $this->consents->observe((string) $recipient, ConsentChannel::Email, RecipientType::Individual, ConsentSourceProvider::PratikTools, ConsentStatus::Revoked,
                    $now, 'unsubscribe:'.$tokenHash, source: 'UNSUBSCRIBE_LINK', metadata: ['explicit_change' => true, 'method' => $method]);

                $via = $this->strategy->mode() === ConsentStrategyMode::ViaReconciliation;
                DB::table('consent_write_intents')->insert([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $data['tenant_id'], 'recipient_id' => $recipient, 'target' => 'IYS', 'channel' => 'EMAIL',
                    'status' => 'RET', 'source' => 'UNSUBSCRIBE', 'state' => 'pending_write_disabled', 'route' => $via ? 'via_ret' : 'direct_api',
                    'reference' => $via ? 'ret_'.Str::lower(Str::random(32)) : null, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->audit->record(AuditAction::EmailUnsubscribed, 'consent_recipient', (string) $recipient,
                    ['method' => $method, 'iys_write' => 'pending_write_disabled', 'route' => $via ? 'via_ret' : 'direct_api'], $actor);

                return 'unsubscribed';
            });
        });
    }
}
