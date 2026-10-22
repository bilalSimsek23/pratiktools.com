<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Tenancy\Permission;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class TenantController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): TenantResource
    {
        Gate::authorize(Permission::TenantView->value);

        return new TenantResource($this->current());
    }

    public function updateSettings(Request $request): TenantResource
    {
        Gate::authorize(Permission::SettingsUpdate->value);

        $hostname = ['nullable', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,63}$/i'];

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'timezone' => ['sometimes', 'timezone:all'],
            'locale' => ['sometimes', 'string', 'max:16', 'regex:/^[a-z]{2}(_[A-Z]{2})?$/'],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'country' => ['sometimes', 'string', 'size:2', 'alpha'],
            'branding' => ['sometimes', 'array:logo_url,primary_color,secondary_color'],
            'branding.logo_url' => ['nullable', 'url:https', 'max:2048'],
            'branding.primary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'branding.secondary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'primary_domain' => $hostname,
            'tracking_domain' => $hostname,
            'wheel_domain' => $hostname,
            'email_sender_domain' => $hostname,
            'sending_mode' => ['sometimes', Rule::in(TenantSetting::SENDING_MODES)],
        ]);

        if (array_key_exists('sending_mode', $data)) {
            Gate::authorize(Permission::SendingModeUpdate->value);
        }

        $tenant = $this->current();

        DB::transaction(function () use ($tenant, $data) {
            $tenantFields = array_intersect_key($data, array_flip(['name', 'timezone', 'locale', 'currency', 'country']));
            $settingFields = array_diff_key($data, $tenantFields);

            foreach (['country', 'currency'] as $upper) {
                if (isset($tenantFields[$upper])) {
                    $tenantFields[$upper] = strtoupper($tenantFields[$upper]);
                }
            }
            $tenant->fill($tenantFields)->save();

            $tenant->settings->fill($settingFields)->save();

            $this->audit->record(AuditAction::TenantSettingsUpdated, 'tenant', $tenant->id, [
                'changed' => array_keys($data),
            ]);
        });

        return new TenantResource($tenant->refresh());
    }

    private function current(): Tenant
    {
        return Tenant::query()->with('settings')->findOrFail($this->context->requireTenant());
    }
}
