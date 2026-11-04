<?php

namespace App\Platform;

use RuntimeException;

/**
 * A Super Admin invitation that cannot be issued (platform:admin:invite);
 * the message is safe to show to the operator (never a token or password).
 */
final class PlatformAdminError extends RuntimeException {}
