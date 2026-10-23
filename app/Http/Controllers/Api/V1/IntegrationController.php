<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\IntegrationResource;
use App\Integrations\ProviderRegistry;
use App\Jobs\VerifyIntegration;
use App\Models\TenantIntegration;
use App\Security\OutboundUrlGuard;
use App\Security\Vault\SecretVault;
use App\Tenancy\Permission;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Integration secrets are write-only: they are accepted on create and on
 * rotation, stored only as vault envelopes, and never returned.
 */
final class IntegrationController extends Controller
{
    public function __construct(
        private readonly SecretVault $vault,
        private readonly ProviderRegistry $providers,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly OutboundUrlGuard $urlGuard,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize(Permission::IntegrationsView->value);

        return IntegrationResource::collection(TenantIntegration::query()->orderBy('provider')->get());
    }

    public function show(TenantIntegration $integration): IntegrationResource
    {
        Gate::authorize(Permission::IntegrationsView->value);

        return new IntegrationResource($integration);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::IntegrationsManage->value);

        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(array_keys(ProviderRegistry::PROVIDERS))],
            'capability' => ['required', 'string', 'max:32'],
            'secret' => ['required', 'string', 'min:8', 'max:8192'],
            'scopes' => ['sometimes', 'array', 'max:50'],
            'scopes.*' => ['string', 'max:100'],
            'settings' => ['sometimes', 'array'],
            'settings.service_url' => ['sometimes', 'string', 'url:https', 'max:255'],
            'settings.storefront_url' => ['sometimes', 'string', 'url:https', 'max:255'],
        ]);

        if (! $this->providers->supports($data['provider'], $data['capability'])) {
            throw ValidationException::withMessages(['capability' => 'The provider does not support this capability.']);
        }

        $settings = $this->settings($data);

        if (TenantIntegration::query()->where('provider', $data['provider'])->where('capability', $data['capability'])->exists()) {
            throw ValidationException::withMessages(['provider' => 'This integration already exists; rotate its secret instead.']);
        }

        $integration = DB::transaction(function () use ($data, $settings) {
            $integration = new TenantIntegration([
                'provider' => $data['provider'],
                'capability' => $data['capability'],
                'scopes' => $data['scopes'] ?? [],
                'settings' => $settings,
                'status' => 'pending',
            ]);
            $integration->tenant_id = $this->context->requireTenant();
            $this->vault->seal($integration, $data['secret']);
            $integration->save();

            $this->audit->record(AuditAction::IntegrationCreated, 'tenant_integration', $integration->id, [
                'provider' => $integration->provider,
                'capability' => $integration->capability,
            ]);
            $this->audit->record(AuditAction::IntegrationSecretCreated, 'tenant_integration', $integration->id, [
                'fingerprint' => $integration->secret_fingerprint,
            ]);

            return $integration;
        });

        return (new IntegrationResource($integration))->response()->setStatusCode(201);
    }

    public function updateSecret(Request $request, TenantIntegration $integration): IntegrationResource
    {
        Gate::authorize(Permission::IntegrationsManage->value);

        $data = $request->validate(['secret' => ['required', 'string', 'min:8', 'max:8192']]);

        DB::transaction(function () use ($integration, $data) {
            $previous = $integration->secret_fingerprint;
            $this->vault->seal($integration, $data['secret']);
            $integration->forceFill(['status' => 'pending', 'last_error' => null, 'last_verified_at' => null])->save();

            $this->audit->record(AuditAction::IntegrationSecretChanged, 'tenant_integration', $integration->id, [
                'previous_fingerprint' => $previous,
                'fingerprint' => $integration->secret_fingerprint,
            ]);
        });

        return new IntegrationResource($integration);
    }

    public function verify(Request $request, TenantIntegration $integration): JsonResponse
    {
        Gate::authorize(Permission::IntegrationsManage->value);

        VerifyIntegration::dispatch($integration->id, $this->context->requireTenant(), $request->user()?->getAuthIdentifier());

        return response()->json(['status' => 'verification_queued'], 202);
    }

    public function destroy(TenantIntegration $integration): JsonResponse
    {
        Gate::authorize(Permission::IntegrationsManage->value);

        DB::transaction(function () use ($integration) {
            $integration->delete();
            $this->audit->record(AuditAction::IntegrationDeleted, 'tenant_integration', $integration->id, [
                'provider' => $integration->provider,
                'capability' => $integration->capability,
            ]);
        });

        return response()->json(status: 204);
    }

    /**
     * Provider settings are an allowlist; Ticimax commerce needs both URLs,
     * and both must pass the outbound URL guard (https, public host).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function settings(array $data): array
    {
        if ($data['provider'] !== 'ticimax' || $data['capability'] !== 'commerce') {
            return [];
        }

        $settings = [];
        foreach (['service_url', 'storefront_url'] as $key) {
            $url = (string) ($data['settings'][$key] ?? '');
            try {
                $this->urlGuard->assertSafe($url);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(["settings.{$key}" => $e->getMessage()]);
            }
            $settings[$key] = $key === 'service_url' ? rtrim($url, '/').'/' : rtrim($url, '/');
        }

        return $settings;
    }
}
