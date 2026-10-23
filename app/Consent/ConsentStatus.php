<?php

namespace App\Consent;

/**
 * Canonical status of one observation. CONFLICT is not a stored status: it
 * is a derived condition (consent_conflicts) when sources disagree.
 */
enum ConsentStatus: string
{
    case Granted = 'GRANTED';
    case Revoked = 'REVOKED';
    case Pending = 'PENDING';
    case Unknown = 'UNKNOWN';
}
