<?php

namespace App\Jobs;

use App\Audit\Actor;
use App\Push\PushDelivery;
use App\Queue\Middleware\SetTenantContext;
use App\Queue\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Sends one push_send_requests row. tries = 1: retries happen inside the
 * client with the same idempotency key, and a duplicate delivery of this
 * job finds the row finished and does nothing.
 */
final class SendPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $requestId, ?string $tenantId = null)
    {
        $this->forTenant($tenantId);
        $this->onQueue('send');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new SetTenantContext, (new WithoutOverlapping("push:{$this->requestId}"))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(PushDelivery $delivery): void
    {
        $delivery->send($this->requestId, Actor::job(self::class));
    }
}
