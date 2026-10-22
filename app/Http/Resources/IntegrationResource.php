<?php

namespace App\Http\Resources;

use App\Models\TenantIntegration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicit allowlist of fields. The secret and its envelope are never part
 * of any response; only a keyed fingerprint is shown.
 *
 * @mixin TenantIntegration
 */
final class IntegrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'capability' => $this->capability,
            'status' => $this->status,
            'has_secret' => $this->hasSecret(),
            'secret_fingerprint' => $this->secret_fingerprint,
            'scopes' => $this->scopes,
            'last_verified_at' => $this->last_verified_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
