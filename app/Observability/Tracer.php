<?php

namespace App\Observability;

use Closure;

/**
 * Extension point for distributed tracing (OpenTelemetry). Connectors wrap
 * provider calls in spans; the default implementation only runs the callback.
 */
interface Tracer
{
    /**
     * @template T
     *
     * @param  array<string, scalar|null>  $attributes
     * @param  Closure(): T  $callback
     * @return T
     */
    public function span(string $name, array $attributes, Closure $callback): mixed;
}
