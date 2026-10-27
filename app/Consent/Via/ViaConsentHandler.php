<?php

namespace App\Consent\Via;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Consent\ConsentConflicts;
use App\Consent\ConsentSourceProvider;
use App\Consent\ConsentStatus;
use App\Consent\ConsentStore;
use App\Events\Customer\CustomerEventName;
use App\Events\Customer\EventRecorder;
use App\Tenancy\Exceptions\TenantMismatch;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Applies verified ViA outcomes inside the tenant's context.
 *
 * - The event's frame must be the tenant's configured frame; the tenant is
 *   never taken from the event.
 * - Idempotent: a reference completes once and a ViA transaction id is
 *   used once; repeats change nothing and return "duplicate".
 * - Consent success: official ONAY (source VIA) and, for newsletter
 *   requests, the NEWSLETTER_SUBSCRIBED event — only now, not at form
 *   submission. If the address is locally blocked (unsubscribe,
 *   suppression) the block stays and a conflict is recorded.
 * - Consent failure: the request fails; no consent is recorded.
 * - ViA Ret success: official RET (source VIA), the unsubscribe intent is
 *   confirmed. Failure: the intent fails; the local suppression stays.
 */
final class ViaConsentHandler
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ViaConsentProvider $via,
        private readonly ConsentStore $consents,
        private readonly ConsentConflicts $conflicts,
        private readonly EventRecorder $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return 'applied'|'failed'|'duplicate'|'unknown_reference'
     */
    public function handle(ViaConsentEvent $event): string
    {
        $frame = $this->via->frame();
        if ($frame === null || ! $this->via->status()->configured()) {
            throw new ViaError('VIA_NOT_CONFIGURED');
        }
        if (! hash_equals($frame->frameId, $event->frameId)) {
            throw new TenantMismatch;
        }

        return DB::transaction(fn () => $event->kind === ViaConsentEvent::RET ? $this->ret($event) : $this->consent($event));
    }

    private function consent(ViaConsentEvent $event): string
    {
        $tenantId = $this->context->requireTenant();
        $request = DB::table('via_consent_requests')->where('tenant_id', $tenantId)->where('reference', $event->reference)->lockForUpdate()->first();
        if ($request === null) {
            return 'unknown_reference';
        }
        if ($request->state !== 'pending' && $request->state !== 'expired'
            || DB::table('via_consent_requests')->where('tenant_id', $tenantId)->where('provider_transaction_id', $event->transactionId)->exists()) {
            return 'duplicate';
        }
        $actor = Actor::system('via');

        if (! $event->success) {
            DB::table('via_consent_requests')->where('tenant_id', $tenantId)->where('id', $request->id)
                ->update(['state' => 'failed', 'failure_code' => self::code($event->failureCode), 'provider_transaction_id' => $event->transactionId, 'completed_at' => now()]);
            $this->audit->record(AuditAction::ViaConsentFailed, 'via_consent_request', $request->id, ['code' => self::code($event->failureCode)], $actor);

            return 'failed';
        }

        $this->consents->observe((string) $request->recipient_id, $event->channel, $event->recipientType, ConsentSourceProvider::Via, ConsentStatus::Granted,
            $event->occurredAt, 'via:'.$event->transactionId, source: 'VIA_FRAME', consentDate: $event->occurredAt, externalId: $event->transactionId,
            metadata: ['via_reference' => $event->reference], evidence: ['via_transaction_id' => $event->transactionId, 'via_reference' => $event->reference, 'form_id' => $request->form_id]);
        DB::table('via_consent_requests')->where('tenant_id', $tenantId)->where('id', $request->id)
            ->update(['state' => 'succeeded', 'provider_transaction_id' => $event->transactionId, 'completed_at' => now()]);

        if ($this->conflicts->locallyRevoked((string) $request->recipient_id, $event->channel)) {
            $this->conflicts->open((string) $request->recipient_id, $event->channel, ConsentConflicts::OFFICIAL_ONAY_AFTER_LOCAL_RET,
                ['source' => 'VIA', 'occurred_at' => $event->occurredAt->toIso8601String()]);
        }
        if ($request->customer_id !== null && $request->purpose === 'newsletter') {
            $form = $request->form_id === null ? null : DB::table('lead_forms')->where('tenant_id', $tenantId)->where('id', $request->form_id)->first(['placement']);
            $this->events->record(CustomerEventName::NewsletterSubscribed, (string) $request->customer_id, $event->occurredAt, 'via', 'via:'.$event->transactionId,
                ['form_id' => $request->form_id, 'placement' => $form->placement ?? null, 'consent_source' => 'VIA']);
        }
        $this->audit->record(AuditAction::ViaConsentCompleted, 'via_consent_request', $request->id, ['channel' => $event->channel->value], $actor);

        return 'applied';
    }

    private function ret(ViaConsentEvent $event): string
    {
        $tenantId = $this->context->requireTenant();
        $intent = DB::table('consent_write_intents')->where('tenant_id', $tenantId)->where('reference', $event->reference)->where('route', 'via_ret')->lockForUpdate()->first();
        if ($intent === null) {
            return 'unknown_reference';
        }
        if (! in_array($intent->state, ['pending_write_disabled', 'pending', 'failed'], true)) {
            return 'duplicate';
        }
        $actor = Actor::system('via');

        if (! $event->success) {
            DB::table('consent_write_intents')->where('tenant_id', $tenantId)->where('id', $intent->id)
                ->update(['state' => 'failed', 'last_error' => self::code($event->failureCode), 'updated_at' => now()]);
            $this->audit->record(AuditAction::ViaRetFailed, 'consent_write_intent', $intent->id, ['code' => self::code($event->failureCode)], $actor);

            return 'failed';
        }

        $this->consents->observe((string) $intent->recipient_id, $event->channel, $event->recipientType, ConsentSourceProvider::Via, ConsentStatus::Revoked,
            $event->occurredAt, 'via:'.$event->transactionId, source: 'VIA_RET', externalId: $event->transactionId, metadata: ['via_reference' => $event->reference]);
        DB::table('consent_write_intents')->where('tenant_id', $tenantId)->where('id', $intent->id)
            ->update(['state' => 'confirmed', 'confirmed_at' => now(), 'last_error' => null, 'updated_at' => now()]);
        $this->audit->record(AuditAction::ViaRetCompleted, 'consent_write_intent', $intent->id, [], $actor);

        return 'applied';
    }

    private static function code(?string $code): string
    {
        $clean = preg_replace('/[^A-Z0-9_]/', '', strtoupper((string) $code));

        return $clean === '' || $clean === null ? 'UNKNOWN' : mb_substr($clean, 0, 64);
    }
}
