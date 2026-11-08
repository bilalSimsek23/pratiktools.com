<?php

namespace App\Consent;

/**
 * Why a recipient may or may not receive a marketing message. Used for
 * audience exclusion reports, so every "no" names its cause.
 */
enum EligibilityReason: string
{
    case Allowed = 'ALLOWED';
    case NoRecipient = 'NO_RECIPIENT';
    case InvalidEmail = 'INVALID_EMAIL';
    case MarketplaceIdentity = 'MARKETPLACE_IDENTITY';
    case LocalSuppressed = 'LOCAL_SUPPRESSED';
    case IysRevoked = 'IYS_REVOKED';
    case SourceRevoked = 'SOURCE_REVOKED';
    case IysUnknown = 'IYS_UNKNOWN';
    case IysPending = 'IYS_PENDING';
    case IysUnavailable = 'IYS_UNAVAILABLE';
    case IysStale = 'IYS_STALE';
    // via_reconciliation: the last applied İYS change report is too old.
    case IysReconciliationStale = 'IYS_RECONCILIATION_STALE';
    case IdentityConflict = 'IDENTITY_CONFLICT';
    // merchant_verified: the attested store permissions cannot back a
    // decision right now (no attestation, store not connected, permission
    // sync missing or too old).
    case MerchantConsentStale = 'MERCHANT_CONSENT_STALE';
    // merchant_verified: no current store e-mail permission, or an opt-out
    // / unsubscribe after it.
    case MerchantNotGranted = 'MERCHANT_NOT_GRANTED';
    case MerchantPolicyReview = 'MERCHANT_POLICY_REVIEW';
    case NoMarketingConsent = 'NO_MARKETING_CONSENT';
    case ChannelNotSupported = 'CHANNEL_NOT_SUPPORTED';

    public function allows(): bool
    {
        return $this === self::Allowed;
    }
}
