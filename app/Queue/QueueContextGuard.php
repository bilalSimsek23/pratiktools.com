<?php

namespace App\Queue;

use App\Tenancy\TenantContext;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;

/**
 * Defense in depth around every job, independent of job middleware:
 * a job always starts with an empty tenant context, and whatever context
 * existed before (an HTTP request that dispatched a sync job, or nothing in
 * a worker) is restored afterwards. A job that forgets the tenant middleware
 * therefore runs without tenant access instead of inheriting one.
 */
final class QueueContextGuard
{
    /** @var list<array{tenant: ?string, user: ?string, mode: string, membership: mixed}> */
    private array $stack = [];

    public function __construct(private readonly TenantContext $context) {}

    public static function register(): void
    {
        $guard = app(self::class);

        Event::listen(JobProcessing::class, fn () => $guard->enter());
        Event::listen(JobProcessed::class, fn () => $guard->leave());
        Event::listen(JobExceptionOccurred::class, fn () => $guard->leave());
    }

    public function enter(): void
    {
        $this->stack[] = $this->context->snapshot();
        $this->context->clear();
    }

    public function leave(): void
    {
        $previous = array_pop($this->stack);

        if ($previous === null) {
            $this->context->clear();

            return;
        }

        $this->context->restore($previous);
        Context::forget('job_id');
    }
}
