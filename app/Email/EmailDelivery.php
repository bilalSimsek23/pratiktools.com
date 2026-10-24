<?php

namespace App\Email;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Consent\RecipientDirectory;
use App\Email\Data\EmailDraft;
use App\Integrations\Errors\ProviderError;
use App\Jobs\SendEmail;
use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queues and sends e-mail, one row per recipient.
 *
 * - queue(): validates the message, stores it with its idempotency key
 *   (a repeated key returns the existing message and queues nothing) and
 *   dispatches SendEmail on the "send" queue.
 * - send(): locks the row; only queued/deferred rows go on (a duplicate
 *   job delivery is skipped), re-runs the guard, sends through the
 *   message's own integration, records the outcome. Quota and throttling
 *   defer; ambiguous timeouts fail without an automatic resend.
 *
 * Logs and audit carry ids, types and reason codes only: no addresses, no
 * subject or body.
 */
final class EmailDelivery
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly TenantContext $context,
        private readonly EmailSendGuard $guard,
        private readonly EmailProviderResolver $providers,
        private readonly RecipientDirectory $recipients,
        private readonly EmailUsage $usage,
        private readonly UnsubscribeTokens $unsubscribe,
        private readonly AuditLogger $audit,
    ) {}

    public function queue(EmailDraft $draft, Actor $actor): string
    {
        $tenantId = $this->context->requireTenant();
        $integration = TenantIntegration::query()->where('capability', 'email')->findOrFail($draft->integrationId);
        $identity = DB::table('email_sender_identities')->where('tenant_id', $tenantId)->where('integration_id', $integration->id)->where('id', $draft->senderIdentityId)->first()
            ?? throw new InvalidEmailMessage('UNKNOWN_SENDER', 'Unknown sender identity for this integration.');
        $key = $draft->idempotencyKey ?? (string) Str::uuid();
        if (preg_match('/^[A-Za-z0-9:_\-.]{8,128}$/', $key) !== 1) {
            throw new InvalidEmailMessage('INVALID_IDEMPOTENCY_KEY', 'Idempotency keys are 8-128 characters of [A-Za-z0-9:_-.].');
        }

        $id = (string) Str::uuid7();
        $normalized = $this->recipients->email(MessageRules::address($draft->to, 'INVALID_RECIPIENT'));
        $recipientId = $normalized === null ? null : ($this->recipients->ensure('email', [$normalized])[$normalized['address']] ?? null);

        // Marketing always carries a one-click unsubscribe for its recipient.
        $headers = $draft->headers;
        if ($draft->type === EmailMessageType::Marketing && $recipientId !== null && ! isset($headers['List-Unsubscribe']) && $this->unsubscribe->available()) {
            $headers += $this->unsubscribe->headers($this->unsubscribe->url($this->unsubscribe->issue($tenantId, $recipientId)));
        }

        // Validates addresses, headers, subject and size before anything is stored.
        $email = new OutboundEmail($id, $key, $draft->type, $draft->to, $identity->from_email, $identity->from_name, $identity->reply_to_email,
            $draft->subject, $draft->html, $draft->text, $headers, $draft->tags);

        $row = DB::selectOne(
            'INSERT INTO email_messages (id, tenant_id, integration_id, sender_identity_id, provider, campaign_id, automation_run_id, snapshot_id, template_version_id,
                customer_id, recipient_id, to_email, from_email, from_name, reply_to, subject, html_body, text_body, tags, metadata, headers, message_type,
                idempotency_key, status, size_bytes, requested_by, send_at, next_attempt_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::jsonb, ?, ?, \'queued\', ?, ?, ?, ?, now(), now())
             ON CONFLICT (tenant_id, idempotency_key) DO NOTHING RETURNING id',
            [
                $id, $tenantId, $integration->id, $identity->id, $integration->provider, $draft->campaignId, $draft->automationRunId, $draft->snapshotId, $draft->templateVersionId,
                $draft->customerId, $recipientId, $email->to, $email->fromEmail, $email->fromName, $email->replyTo, $email->subject, $email->html, $email->text,
                json_encode((object) $email->tags), json_encode((object) $draft->metadata), json_encode((object) $email->headers), $draft->type->value,
                $key, $email->sizeBytes(), self::actor($actor), $draft->sendAt, $draft->sendAt,
            ],
        );

        if ($row === null) {
            return (string) DB::table('email_messages')->where('tenant_id', $tenantId)->where('idempotency_key', $key)->value('id');
        }

        $this->audit->record(AuditAction::EmailSendRequested, 'email_message', $id, [
            'type' => $draft->type->value, 'provider' => $integration->provider, 'mode' => $this->guard->mode()->value, 'campaign_id' => $draft->campaignId,
        ], $actor);
        $this->dispatch($id, $draft->sendAt);

        return $id;
    }

    /**
     * @return 'sent'|'blocked'|'deferred'|'failed'|'skipped'
     */
    public function send(string $messageId, Actor $actor): string
    {
        $tenantId = $this->context->requireTenant();
        $row = DB::transaction(function () use ($tenantId, $messageId) {
            $row = DB::table('email_messages')->where('tenant_id', $tenantId)->where('id', $messageId)->lockForUpdate()->first();
            if ($row === null || ! in_array($row->status, ['queued', 'deferred'], true)) {
                return null;
            }
            if ($row->next_attempt_at !== null && CarbonImmutable::parse($row->next_attempt_at)->isFuture()) {
                return null;
            }
            DB::table('email_messages')->where('id', $row->id)->update(['status' => 'sending', 'attempts' => $row->attempts + 1, 'updated_at' => now()]);

            return $row;
        });
        if ($row === null) {
            return 'skipped';
        }

        $integration = TenantIntegration::query()->findOrFail($row->integration_id);

        try {
            $email = $this->outbound($row);
            $authorization = $this->guard->authorize($integration, $row, $email);
            $provider = $this->providers->provider($integration, 'email_send', $actor);
            $this->usage->add($integration->provider, ['emails_attempted' => 1]);
            $result = $provider->send($authorization, $email);
        } catch (InvalidEmailMessage $e) {
            return $this->block($row, $e->reason, $actor);
        } catch (EmailSendBlocked $e) {
            return $e->retryAt !== null ? $this->defer($row, $e->reason, $e->retryAt, $actor) : $this->block($row, $e->reason, $actor);
        } catch (ProviderError $e) {
            return $this->providerFailure($row, $integration, $e, $actor);
        } catch (Throwable $e) {
            $this->finish($row->id, ['status' => 'failed', 'error_code' => class_basename($e)]);
            $this->audit->record(AuditAction::EmailFailed, 'email_message', $row->id, ['error' => class_basename($e)], $actor);
            report($e);

            return 'failed';
        }

        $this->finish($row->id, ['status' => 'sent', 'provider_message_id' => $result->providerMessageId, 'sent_at' => now(), 'error_code' => null, 'block_reason' => null, 'next_attempt_at' => null]);
        $this->usage->add($integration->provider, ['emails_sent' => 1, 'bytes_sent' => (int) $row->size_bytes, 'provider_requests' => $result->providerRequests]);
        $this->audit->record(AuditAction::EmailSent, 'email_message', $row->id, ['type' => $row->message_type, 'provider' => $integration->provider, 'provider_message_id' => $result->providerMessageId], $actor);

        return 'sent';
    }

    private function providerFailure(object $row, TenantIntegration $integration, ProviderError $e, Actor $actor): string
    {
        $this->usage->add($integration->provider, ['provider_requests' => 1]);

        if ($e->category === ProviderError::QUOTA_EXHAUSTED || ($e->category === ProviderError::RATE_LIMITED && $row->attempts < self::MAX_ATTEMPTS)) {
            return $this->defer($row, strtoupper($e->category), CarbonImmutable::now()->addMilliseconds(max($e->retryAfterMs, 30_000)), $actor);
        }
        if ($e->category === ProviderError::RETRYABLE && $row->attempts < self::MAX_ATTEMPTS) {
            return $this->defer($row, 'PROVIDER_RETRYABLE', CarbonImmutable::now()->addMinutes(2 ** $row->attempts), $actor);
        }
        if ($e->category === ProviderError::AUTH) {
            $integration->forceFill(['status' => 'action_required', 'last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
        }

        // AMBIGUOUS: SES may have accepted it; never resent automatically.
        $code = $e->category === ProviderError::AMBIGUOUS ? 'AMBIGUOUS_TIMEOUT' : strtoupper($e->category).':'.mb_substr((string) $e->operation, 0, 32);
        $this->finish($row->id, ['status' => 'failed', 'error_code' => mb_substr($code, 0, 64)]);
        $this->audit->record(AuditAction::EmailFailed, 'email_message', $row->id, ['error' => $e->category, 'provider' => $integration->provider], $actor);

        return 'failed';
    }

    private function block(object $row, string $reason, Actor $actor): string
    {
        $this->finish($row->id, ['status' => 'blocked', 'block_reason' => mb_substr($reason, 0, 48), 'next_attempt_at' => null]);
        $this->audit->record(AuditAction::EmailBlocked, 'email_message', $row->id, ['reason' => $reason, 'type' => $row->message_type], $actor);

        return 'blocked';
    }

    private function defer(object $row, string $reason, CarbonImmutable $retryAt, Actor $actor): string
    {
        $this->finish($row->id, ['status' => 'deferred', 'block_reason' => mb_substr($reason, 0, 48), 'next_attempt_at' => $retryAt]);
        $this->audit->record(AuditAction::EmailDeferred, 'email_message', $row->id, ['reason' => $reason, 'retry_at' => $retryAt->toIso8601String()], $actor);
        $this->dispatch($row->id, $retryAt);

        return 'deferred';
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function finish(string $id, array $values): void
    {
        DB::table('email_messages')->where('tenant_id', $this->context->requireTenant())->where('id', $id)->update($values + ['updated_at' => now()]);
    }

    private function dispatch(string $id, ?CarbonImmutable $at): void
    {
        $job = SendEmail::dispatch($id, $this->context->requireTenant());
        if ($at !== null && $at->isFuture()) {
            $job->delay($at);
        }
    }

    private function outbound(object $row): OutboundEmail
    {
        return new OutboundEmail($row->id, $row->idempotency_key, EmailMessageType::from($row->message_type), $row->to_email, $row->from_email, $row->from_name,
            $row->reply_to, $row->subject, $row->html_body, $row->text_body, (array) json_decode($row->headers, true), (array) json_decode($row->tags, true));
    }

    private static function actor(Actor $actor): string
    {
        return mb_substr($actor->type.($actor->id !== null ? ':'.$actor->id : ''), 0, 64);
    }
}
