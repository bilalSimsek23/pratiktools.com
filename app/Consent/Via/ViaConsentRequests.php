<?php

namespace App\Consent\Via;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ViA consent acquisitions started by PratikTools (lead forms in "via"
 * mode). A request is only a correlation record: it never grants consent.
 * The recipient stays IYS_PENDING while it is open, and becomes ONAY only
 * through a verified ViA success event (ViaConsentHandler).
 */
final class ViaConsentRequests
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return string the opaque correlation reference
     */
    public function open(string $recipientId, ?string $customerId, ?string $formId, ?string $submissionId, string $purpose = 'newsletter'): string
    {
        $reference = 'via_'.Str::lower(Str::random(32));
        DB::table('via_consent_requests')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->context->requireTenant(), 'recipient_id' => $recipientId, 'customer_id' => $customerId,
            'form_id' => $formId, 'submission_id' => $submissionId, 'reference' => $reference, 'channel' => 'EMAIL', 'purpose' => $purpose,
            'state' => 'pending', 'created_at' => now(),
        ]);

        return $reference;
    }

    /**
     * Pending requests past the window are expired (fail-closed: never ONAY).
     */
    public function expire(): int
    {
        return DB::table('via_consent_requests')->where('tenant_id', $this->context->requireTenant())->where('state', 'pending')
            ->where('created_at', '<', now()->subHours((int) config('consent.via.pending_hours')))
            ->update(['state' => 'expired', 'completed_at' => now()]);
    }
}
