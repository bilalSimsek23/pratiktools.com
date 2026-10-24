<?php

namespace App\Jobs;

use App\Audit\Actor;
use App\Email\EmailDelivery;
use App\Queue\Middleware\SetTenantContext;
use App\Queue\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Sends one email_messages row. tries = 1: safe retries happen inside the
 * transport, deferrals re-dispatch explicitly, and a duplicate delivery
 * of this job finds the row no longer queued and does nothing.
 */
final class SendEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $messageId, ?string $tenantId = null)
    {
        $this->forTenant($tenantId);
        $this->onQueue('send');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new SetTenantContext, (new WithoutOverlapping("email:{$this->messageId}"))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(EmailDelivery $delivery): void
    {
        $delivery->send($this->messageId, Actor::job(self::class));
    }
}
