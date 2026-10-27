<?php

namespace App\Leads;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tenant lead forms. The public id is a random, form-scoped identifier for
 * the browser (no tenant credential); a form accepts submissions only
 * while active and only from its allowed origins. Changing the consent
 * text bumps its version, so every submission records which text the
 * person saw.
 */
final class LeadForms
{
    public const PLACEMENTS = ['popup', 'footer', 'landing_page', 'checkout'];

    // local_only: checkbox evidence + pending İYS intent; via: İYS ViA
    // acquisition, ONAY only after a verified ViA success.
    public const CONSENT_MODES = ['local_only', 'via'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $allowedOrigins
     * @param  list<string>  $tags
     */
    public function create(string $name, string $placement, string $consentText, string $successMessage, array $allowedOrigins, Actor $actor,
        string $locale = 'tr', array $tags = [], bool $doubleOptIn = false): string
    {
        if (trim($name) === '' || mb_strlen($name) > 120 || ! in_array($placement, self::PLACEMENTS, true)) {
            throw new LeadError('INVALID_FORM', 'A name and a placement (popup, footer, landing_page, checkout) are required.');
        }
        $this->checkText($consentText, $successMessage);
        $origins = self::origins($allowedOrigins);
        $id = (string) Str::uuid7();
        DB::table('lead_forms')->insert([
            'id' => $id, 'tenant_id' => $this->context->requireTenant(), 'public_id' => 'lf_'.Str::lower(Str::random(26)), 'name' => trim($name), 'placement' => $placement,
            'active' => false, 'consent_text' => trim($consentText), 'consent_version' => 1, 'consent_text_hash' => hash('sha256', trim($consentText)),
            'success_message' => trim($successMessage), 'locale' => mb_substr($locale, 0, 8), 'tags' => json_encode(array_slice(array_map('strval', $tags), 0, 10)),
            'allowed_origins' => json_encode($origins), 'double_opt_in' => $doubleOptIn, 'created_by' => mb_substr($actor->type.':'.$actor->id, 0, 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record(AuditAction::LeadFormCreated, 'lead_form', $id, ['placement' => $placement, 'origins' => count($origins)], $actor);

        return $id;
    }

    public function setActive(string $formId, bool $active, Actor $actor): void
    {
        $updated = DB::table('lead_forms')->where('tenant_id', $this->context->requireTenant())->where('id', $formId)->update(['active' => $active, 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new LeadError('NOT_FOUND', 'Unknown form.');
        }
        $this->audit->record($active ? AuditAction::LeadFormActivated : AuditAction::LeadFormDeactivated, 'lead_form', $formId, [], $actor);
    }

    public function setConsentMode(string $formId, string $mode, Actor $actor): void
    {
        if (! in_array($mode, self::CONSENT_MODES, true)) {
            throw new LeadError('INVALID_FORM', 'Consent mode must be local_only or via.');
        }
        $updated = DB::table('lead_forms')->where('tenant_id', $this->context->requireTenant())->where('id', $formId)->update(['consent_mode' => $mode, 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new LeadError('NOT_FOUND', 'Unknown form.');
        }
        $this->audit->record(AuditAction::LeadFormConsentModeChanged, 'lead_form', $formId, ['mode' => $mode], $actor);
    }

    public function changeConsentText(string $formId, string $consentText, Actor $actor): int
    {
        return DB::transaction(function () use ($formId, $consentText, $actor) {
            $form = DB::table('lead_forms')->where('tenant_id', $this->context->requireTenant())->where('id', $formId)->lockForUpdate()->first()
                ?? throw new LeadError('NOT_FOUND', 'Unknown form.');
            $this->checkText($consentText, $form->success_message);
            $version = (int) $form->consent_version + 1;
            DB::table('lead_forms')->where('tenant_id', $form->tenant_id)->where('id', $formId)
                ->update(['consent_text' => trim($consentText), 'consent_version' => $version, 'consent_text_hash' => hash('sha256', trim($consentText)), 'updated_at' => now()]);
            $this->audit->record(AuditAction::LeadFormConsentChanged, 'lead_form', $formId, ['version' => $version, 'hash' => hash('sha256', trim($consentText))], $actor);

            return $version;
        });
    }

    /**
     * @param  list<string>  $origins
     * @return list<string>
     */
    public static function origins(array $origins): array
    {
        $clean = [];
        foreach ($origins as $o) {
            if (preg_match('#^https://[a-z0-9.-]+(:\d{2,5})?$#', strtolower(trim($o))) !== 1) {
                throw new LeadError('INVALID_ORIGIN', 'Allowed origins are https://host[:port] without a path.');
            }
            $clean[] = strtolower(trim($o));
        }

        return array_values(array_unique($clean));
    }

    private function checkText(string $consentText, string $successMessage): void
    {
        if (trim($consentText) === '' || mb_strlen($consentText) > 4000 || trim($successMessage) === '' || mb_strlen($successMessage) > 300) {
            throw new LeadError('INVALID_FORM', 'Consent text (≤ 4000) and success message (≤ 300) are required.');
        }
    }
}
