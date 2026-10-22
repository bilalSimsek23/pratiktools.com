<?php

namespace App\Logging;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Context;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Adds the standard correlation fields to every log record:
 * tenant_id, request_id, job_id, provider, run_id and error_class.
 */
final class AddPlatformContext implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $tenantContext = app()->bound(TenantContext::class) ? app(TenantContext::class) : null;
        $exception = $record->context['exception'] ?? null;

        $fields = [
            'tenant_id' => $tenantContext?->tenantId() ?? Context::get('tenant_id'),
            'request_id' => Context::get('request_id'),
            'job_id' => Context::get('job_id'),
            'provider' => $record->context['provider'] ?? Context::get('provider'),
            'run_id' => $record->context['run_id'] ?? Context::get('run_id'),
            'error_class' => $exception instanceof \Throwable ? $exception::class : null,
        ];

        return $record->with(extra: array_merge($record->extra, array_filter($fields, fn ($v) => $v !== null)));
    }
}
