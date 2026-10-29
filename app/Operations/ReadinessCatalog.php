<?php

namespace App\Operations;

/**
 * Human-readable explanation and operator action per readiness reason code.
 */
final class ReadinessCatalog
{
    private const ENTRIES = [
        'CONTENT_MISSING' => ['No content selected.', 'Select a published marketing template version.'],
        'CONTENT_NOT_PUBLISHED' => ['The selected template version is a draft.', 'Publish the template version (content.publish).'],
        'CONTENT_NOT_MARKETING' => ['The template is not a marketing template.', 'Choose a marketing template.'],
        'AUDIENCE_EMPTY' => ['The audience has no members.', 'Choose or adjust the segment.'],
        'AUDIENCE_MISSING' => ['No audience selected.', 'Select a segment or snapshot.'],
        'SENDER_MISSING' => ['No sender identity selected.', 'Select a verified sender identity.'],
        'SENDER_NOT_VERIFIED' => ['The sender identity is not verified at the provider.', 'Verify the domain in SES, then run email:sender refresh.'],
        'SENDER_INTEGRATION_MISMATCH' => ['The sender belongs to another e-mail integration.', 'Select a sender of the campaign\'s integration.'],
        'DKIM_NOT_VERIFIED' => ['DKIM is not verified for the sending domain.', 'Publish the DKIM CNAME records shown under Sender identities.'],
        'MAIL_FROM_NOT_CONFIGURED' => ['No custom MAIL FROM domain (SPF not aligned; DMARC aligns via DKIM).', 'Optional: configure a custom MAIL FROM subdomain in SES.'],
        'EMAIL_UNSUBSCRIBE_KEY_MISSING' => ['Unsubscribe links cannot be signed: EMAIL_UNSUBSCRIBE_KEY is not configured.', 'Add EMAIL_UNSUBSCRIBE_KEY as Laravel Cloud Secret.'],
        'IYS_UNAVAILABLE' => ['İYS API access is not configured / consent cannot be verified.', 'Complete İYS API access and the F2 shadow sync (F2 GO).'],
        'VIA_NOT_CONFIGURED' => ['No ViA configuration exists for this tenant.', 'Operator: record the ViA form (consent:via).'],
        'VIA_UNSUPPORTED_FORM' => ['Only an OTP ViA form with ETK approval is supported.', 'Operator: check the form and approval type (consent:via).'],
        'VIA_FORM_ID_MISSING' => ['The ViA Form ID is not configured.', 'Operator: record the Form ID from the İYS portal (consent:via --form-id-stdin).'],
        'VIA_FORM_DRAFT' => ['The ViA form has not been submitted for İYS approval.', 'Submit the form for approval in the İYS portal.'],
        'VIA_FORM_PENDING_APPROVAL' => ['ViA OTP form is awaiting İYS approval.', 'Wait for İYS; after seeing "Onaylandı" in the İYS portal an admin marks the form approved (Entegrasyonlar).'],
        'VIA_FORM_REJECTED' => ['The ViA form was rejected by İYS.', 'Fix the form in the İYS portal and submit it again.'],
        'VIA_FORM_DISABLED' => ['The ViA form is disabled.', 'Re-submit or re-enable the form in the İYS portal.'],
        'VIA_TRANSPORT_NOT_CONFIGURED' => ['Waiting for the official İYS ViA OTP integration specification.', 'Provide the official ViA OTP documentation / integration details from İYS.'],
        'VIA_FAKE_TRANSPORT_IN_PRODUCTION' => ['A test ViA transport is bound in production; ViA is refused.', 'Platform team: remove the test binding.'],
        'IYS_RECONCILIATION_POLICY_MISSING' => ['No reconciliation freshness policy is set (IYS_RECONCILIATION_MAX_AGE).', 'Operator/legal decision: set IYS_RECONCILIATION_MAX_AGE (hours).'],
        'IYS_RECONCILIATION_MISSING' => ['No İYS change report has been applied yet.', 'Upload and apply the İYS daily change report (İYS > Mutabakat).'],
        'IYS_RECONCILIATION_STALE' => ['The last applied İYS change report is older than the freshness policy.', 'Apply the latest İYS daily change report (İYS > Mutabakat).'],
        'NO_ELIGIBLE_RECIPIENTS' => ['No recipient is currently eligible (İYS ONAY, suppressions, frequency cap).', 'Wait for İYS consent data; review exclusions in the audience preview.'],
        'SES_SANDBOX' => ['AWS SES account is still in sandbox.', 'Wait for AWS SES production access (support case).'],
        'PROVIDER_STATUS_UNKNOWN' => ['The provider account status has not been checked.', 'Run email:ses check (read-only).'],
        'SENDING_MODE_OFF' => ['E-mail sending is off.', 'Operator decision: EMAIL_SENDING_MODE and tenant sending mode.'],
        'SENDING_MODE_TEST_ONLY' => ['Only test sending is allowed.', 'Live sending requires all external blockers to be resolved.'],
        'DISPATCH_NOT_AVAILABLE' => ['Live campaign dispatch is not available in this phase.', 'No action: live dispatch is planned after İYS and SES are ready.'],
    ];

    /**
     * @return array{explanation: string, action: string}
     */
    public static function explain(?string $code): array
    {
        [$explanation, $action] = self::ENTRIES[$code] ?? ['Unknown blocker.', 'Contact the platform team.'];

        return ['explanation' => $explanation, 'action' => $action];
    }

    /**
     * Readiness checks with explanations.
     *
     * @param  array{checks: list<array{check: string, status: string, reason: ?string, group: string}>}  $readiness
     * @return list<array<string, mixed>>
     */
    public static function rows(array $readiness): array
    {
        return array_map(fn (array $c) => $c + ($c['reason'] === null ? ['explanation' => null, 'action' => null] : self::explain($c['reason'])), $readiness['checks']);
    }
}
