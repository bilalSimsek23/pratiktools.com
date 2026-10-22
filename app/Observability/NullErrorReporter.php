<?php

namespace App\Observability;

use Throwable;

final class NullErrorReporter implements ErrorReporter
{
    public function report(Throwable $e, array $context = []): void {}
}
