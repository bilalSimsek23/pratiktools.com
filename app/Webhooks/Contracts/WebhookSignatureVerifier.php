<?php

namespace App\Webhooks\Contracts;

use Illuminate\Http\Request;

interface WebhookSignatureVerifier
{
    /**
     * @return 'valid'|'invalid'|'missing'
     */
    public function verify(Request $request, string $tenantId): string;
}
