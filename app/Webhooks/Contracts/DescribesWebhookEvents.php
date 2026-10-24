<?php

namespace App\Webhooks\Contracts;

use Illuminate\Http\Request;

/**
 * Optional for signature verifiers whose provider does not use the
 * generic event id/type fields (e.g. SNS envelopes: MessageId, Type).
 */
interface DescribesWebhookEvents
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function eventType(Request $request, array $payload): ?string;

    /**
     * Stable id of the delivery, used for replay protection in the inbox.
     *
     * @param  array<string, mixed>  $payload
     */
    public function eventId(Request $request, array $payload): ?string;
}
