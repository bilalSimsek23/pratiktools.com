<?php

namespace App\Tenancy;

use RuntimeException;

/**
 * A provisioning or membership request that cannot be applied; the message
 * is safe to show to the operator (no secret, no other tenant's data).
 */
final class ProvisioningError extends RuntimeException {}
