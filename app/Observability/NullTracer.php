<?php

namespace App\Observability;

use Closure;

final class NullTracer implements Tracer
{
    public function span(string $name, array $attributes, Closure $callback): mixed
    {
        return $callback();
    }
}
