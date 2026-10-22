<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
final class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'country' => $this->country,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'locale' => $this->locale,
            'webhook_public_id' => $this->public_id,
            'settings' => [
                'sending_mode' => $this->settings?->sending_mode,
                'branding' => $this->settings->branding ?? [],
                'primary_domain' => $this->settings?->primary_domain,
                'tracking_domain' => $this->settings?->tracking_domain,
                'wheel_domain' => $this->settings?->wheel_domain,
                'email_sender_domain' => $this->settings?->email_sender_domain,
            ],
        ];
    }
}
