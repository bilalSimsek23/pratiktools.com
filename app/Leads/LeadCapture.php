<?php

namespace App\Leads;

use App\Commerce\Identity\CustomerIdentityService as Identities;
use App\Consent\ConsentChannel;
use App\Consent\ConsentSourceProvider;
use App\Consent\ConsentStatus;
use App\Consent\ConsentStore;
use App\Consent\RecipientDirectory;
use App\Consent\RecipientType;
use App\Consent\Via\ViaConsentRequests;
use App\Events\Customer\CustomerEventName;
use App\Events\Customer\EventRecorder;
use App\Leads\Contracts\BotVerifier;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Public lead/newsletter submissions.
 *
 * - The tenant comes only from the form's public id (SECURITY DEFINER
 *   lookup of active forms of active tenants), never from the body.
 * - Only allowed browser origins; honeypot and bot verifier; the caller
 *   applies rate limits and the size limit.
 * - Idempotent: one submission per idempotency key (client submission_id,
 *   else form + address + consent + day), enforced by a unique index;
 *   consent observation, İYS intent and newsletter event happen once.
 * - Identity: normalized address, marketplace relay addresses kept apart,
 *   no merge on collision, never by phone; a new address becomes a lead
 *   customer only when no customer owns it.
 * - Consent: the checkbox is a store-side observation (LEAD_FORM), never
 *   İYS ONAY. Evidence: form, consent text version and hash, placement,
 *   time; no IP.
 *   consent_mode local_only: the İYS ONAY is a pending_write_disabled
 *   intent and NEWSLETTER_SUBSCRIBED is recorded now.
 *   consent_mode via: a ViA consent request is opened (IYS_PENDING); only
 *   a verified ViA success makes it ONAY and records
 *   NEWSLETTER_SUBSCRIBED (ViaConsentHandler). No İYS intent: ViA writes
 *   to İYS itself.
 *
 * The outcome is for metrics only; the HTTP answer is the same for every
 * accepted, duplicate or bot submission (no enumeration).
 */
final class LeadCapture
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RecipientDirectory $recipients,
        private readonly Identities $identities,
        private readonly ConsentStore $consents,
        private readonly EventRecorder $events,
        private readonly LeadMetrics $metrics,
        private readonly BotVerifier $bots,
        private readonly ViaConsentRequests $via,
    ) {}

    public function tenantOf(string $publicId): ?string
    {
        if (preg_match('/^lf_[a-z0-9]{26}$/', $publicId) !== 1) {
            return null;
        }
        $row = DB::selectOne('SELECT app_lead_form_tenant(?) AS tenant_id', [$publicId]);

        return $row?->tenant_id === null ? null : (string) $row->tenant_id;
    }

    /**
     * Call inside the form's tenant context.
     *
     * @param  array<string, mixed>  $input
     * @return array{outcome: string, message: string}
     */
    public function submit(string $publicId, array $input, ?string $origin, ?string $ip = null): array
    {
        $tenantId = $this->context->requireTenant();
        $form = DB::table('lead_forms')->where('tenant_id', $tenantId)->where('public_id', $publicId)->where('active', true)->first()
            ?? throw new LeadError('FORM_INACTIVE', 'Form not accepting submissions.');
        $generic = ['message' => (string) $form->success_message];

        if (! in_array(strtolower((string) $origin), (array) json_decode($form->allowed_origins, true), true)) {
            $this->metrics->add($form->id, ['origin_refused' => 1]);

            return ['outcome' => 'origin_refused'] + $generic;
        }
        $this->metrics->add($form->id, ['submissions' => 1]);

        if (trim((string) ($input['website'] ?? '')) !== '' || ! $this->bots->passes(is_string($input['bot_token'] ?? null) ? $input['bot_token'] : null, $ip)) {
            $this->metrics->add($form->id, ['bot_suspected' => 1]);

            return ['outcome' => 'bot_suspected'] + $generic;
        }

        $normalized = $this->recipients->email(is_string($input['email'] ?? null) ? mb_substr($input['email'], 0, 320) : null);
        $domain = $normalized === null ? '' : substr($normalized['address'], strrpos($normalized['address'], '@') + 1);
        if ($normalized === null || ! $normalized['valid'] || strlen($normalized['address']) > 254 || in_array($domain, (array) config('leads.disposable_domains'), true)) {
            $this->metrics->add($form->id, ['invalid' => 1]);

            return ['outcome' => 'invalid'] + $generic;
        }
        $consent = ($input['consent'] ?? false) === true;
        $address = $normalized['address'];
        $day = CarbonImmutable::now()->toDateString();
        $clientId = is_string($input['submission_id'] ?? null) && Str::isUuid($input['submission_id']) ? strtolower($input['submission_id']) : null;
        $key = hash('sha256', $clientId !== null ? "{$form->id}|client|{$clientId}" : "{$form->id}|{$address}|".($consent ? 1 : 0)."|{$form->consent_version}|{$day}");

        if (DB::table('lead_submissions')->where('tenant_id', $tenantId)->where('idempotency_key', $key)->exists()) {
            $this->metrics->add($form->id, ['duplicate' => 1]);

            return ['outcome' => 'duplicate'] + $generic;
        }

        $outcome = DB::transaction(fn () => $this->record($form, $normalized, $consent, $key, $input));
        $this->metrics->add($form->id, $outcome === 'duplicate' ? ['duplicate' => 1] : array_filter([
            'accepted' => 1, 'consent_checked' => $consent ? 1 : 0, 'consent_unchecked' => $consent ? 0 : 1,
        ]));

        return ['outcome' => $outcome] + $generic;
    }

    /**
     * @param  array{address: string, valid: bool, reason: ?string, marketplace: bool}  $normalized
     * @param  array<string, mixed>  $input
     */
    private function record(object $form, array $normalized, bool $consent, string $key, array $input): string
    {
        $tenantId = $this->context->requireTenant();
        $address = $normalized['address'];
        $now = CarbonImmutable::now();
        $isNew = $this->recipients->lookup('email', [$address]) === [];
        $recipientId = $this->recipients->ensure('email', [$normalized])[$address];

        $customerId = null;
        $outcome = 'accepted';
        if ($normalized['marketplace']) {
            $outcome = 'marketplace_relay';
        } elseif ($this->collision($recipientId, $address)) {
            $outcome = 'identity_conflict';
        } else {
            $customerId = $this->identities->find(Identities::EMAIL, $address) ?? $this->leadCustomer($address, $form);
            $this->recipients->link([['recipient_id' => $recipientId, 'customer_id' => $customerId, 'link_source' => 'lead_form']]);
        }

        $optIn = $form->double_opt_in && $consent && $outcome === 'accepted';
        $token = $optIn ? Str::random(40) : null;
        $page = is_string($input['page_url'] ?? null) ? parse_url($input['page_url']) : null;
        $utm = fn (string $k) => is_string($input[$k] ?? null) && preg_match('/^[A-Za-z0-9_.\-]{1,'.(int) config('leads.utm_max_length').'}$/', $input[$k]) === 1 ? $input[$k] : null;

        $id = DB::selectOne(
            'INSERT INTO lead_submissions (id, tenant_id, form_id, idempotency_key, email_hash, recipient_id, customer_id, outcome, new_recipient, consent_checked, consent_version,
                consent_text_hash, placement, page_host, page_path, utm_source, utm_medium, utm_campaign, locale, opt_in_status, opt_in_token_hash, opt_in_expires_at, received_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now()) ON CONFLICT (tenant_id, idempotency_key) DO NOTHING RETURNING id',
            [(string) Str::uuid7(), $tenantId, $form->id, $key, hash_hmac('sha256', $address, (string) config('app.key')), $recipientId, $customerId, $outcome, $isNew, $consent,
                $form->consent_version, $form->consent_text_hash, $form->placement,
                is_array($page) && isset($page['host']) ? mb_substr(strtolower((string) $page['host']), 0, 253) : null,
                is_array($page) && isset($page['path']) ? mb_substr((string) $page['path'], 0, 300) : null,
                $utm('utm_source'), $utm('utm_medium'), $utm('utm_campaign'), is_string($input['locale'] ?? null) ? mb_substr($input['locale'], 0, 8) : $form->locale,
                $optIn ? 'pending' : 'none', $token === null ? null : hash('sha256', $token), $optIn ? $now->addHours((int) config('leads.double_opt_in_hours')) : null],
        );
        if ($id === null) {
            return 'duplicate';
        }
        if ($isNew) {
            $this->metrics->add($form->id, ['new_recipients' => 1]);
        }

        if ($consent && $outcome !== 'marketplace_relay') {
            // Store-side evidence only; İYS decides marketing eligibility.
            $this->consents->observe($recipientId, ConsentChannel::Email, RecipientType::Individual, ConsentSourceProvider::LeadForm,
                $optIn ? ConsentStatus::Pending : ConsentStatus::Granted, $now, 'lead:'.$key, source: 'LEAD_FORM_'.strtoupper($form->placement), consentDate: $now,
                metadata: ['form_id' => $form->id, 'consent_version' => (int) $form->consent_version, 'double_opt_in' => $optIn ? 'pending' : 'not_required'],
                evidence: ['form_public_id' => $form->public_id, 'consent_version' => (int) $form->consent_version, 'consent_text_hash' => $form->consent_text_hash,
                    'placement' => $form->placement, 'captured_at' => $now->toIso8601String()]);
            if (($form->consent_mode ?? 'local_only') === 'via') {
                $this->via->open($recipientId, $customerId, $form->id, (string) $id->id);

                return $outcome;
            }
            DB::table('consent_write_intents')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'recipient_id' => $recipientId, 'target' => 'IYS',
                'channel' => 'EMAIL', 'status' => 'ONAY', 'source' => 'LEAD_FORM', 'state' => 'pending_write_disabled', 'created_at' => $now, 'updated_at' => $now]);

            if ($customerId !== null) {
                $this->events->record(CustomerEventName::NewsletterSubscribed, $customerId, $now, 'lead_form', 'lead:'.$key,
                    ['form_id' => $form->id, 'placement' => $form->placement, 'consent_version' => (int) $form->consent_version]);
            }
        }

        return $outcome;
    }

    /**
     * Shared address (several customers) or an identity merge under review.
     */
    private function collision(string $recipientId, string $address): bool
    {
        $tenantId = $this->context->requireTenant();

        return DB::table('recipient_customers')->where('tenant_id', $tenantId)->where('recipient_id', $recipientId)->count() > 1
            || DB::table('customer_merges')->where('tenant_id', $tenantId)->where('identity_type', Identities::EMAIL)->where('identity_hash', hash('sha256', $address))
                ->where('status', 'pending_review')->exists();
    }

    private function leadCustomer(string $address, object $form): string
    {
        $id = (string) Str::uuid7();
        DB::table('customers')->insert(['id' => $id, 'tenant_id' => $this->context->requireTenant(), 'email' => $address, 'is_guest' => true,
            'attributes' => json_encode(['source' => 'lead_form', 'form_id' => $form->id]), 'source_consent' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        if (! $this->identities->attach($id, Identities::EMAIL, $address, 'lead_form')) {
            // Lost a race to another writer: use the owner, drop nothing.
            return (string) $this->identities->find(Identities::EMAIL, $address);
        }

        return $id;
    }
}
