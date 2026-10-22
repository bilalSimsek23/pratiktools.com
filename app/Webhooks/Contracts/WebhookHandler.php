<?php

namespace App\Webhooks\Contracts;

use App\Models\WebhookInboxItem;

interface WebhookHandler
{
    /**
     * Process one inbox item inside its tenant context. Must be idempotent:
     * the inbox guarantees at-most-once successful processing, but a handler
     * can still be retried after a failure.
     */
    public function handle(WebhookInboxItem $item): void;
}
