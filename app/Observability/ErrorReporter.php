<?php

namespace App\Observability;

use Throwable;

/**
 * Extension point for an external error tracker (Sentry, etc.). Context
 * passed here has already been through the log redactor.
 */
interface ErrorReporter
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function report(Throwable $e, array $context = []): void;
}
