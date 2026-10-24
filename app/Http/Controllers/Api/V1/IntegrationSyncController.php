<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Actor;
use App\Commerce\ConnectionTester;
use App\Commerce\DataPreview;
use App\Commerce\Sync\SyncEntity;
use App\Commerce\Sync\SyncMode;
use App\Commerce\SyncHealth;
use App\Http\Controllers\Controller;
use App\Jobs\SyncCommerceEntity;
use App\Models\TenantIntegration;
use App\Push\PushMetrics;
use App\Tenancy\Permission;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Connection test, sync health, sync trigger and data preview of a
 * commerce integration. Every provider call is read-only.
 */
final class IntegrationSyncController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function test(Request $request, TenantIntegration $integration, ConnectionTester $tester): JsonResponse
    {
        Gate::authorize(Permission::IntegrationsManage->value);
        $this->assertCommerce($integration);

        $check = $tester->test($integration, Actor::user((string) $request->user()?->getAuthIdentifier()));

        return response()->json([
            'ok' => $check->ok,
            'status' => $integration->status,
            'checks' => $check->checks,
            'error_category' => $check->errorCategory,
            'message' => $check->message,
        ], $check->ok ? 200 : 422);
    }

    public function status(TenantIntegration $integration, SyncHealth $health): JsonResponse
    {
        Gate::authorize(Permission::IntegrationsView->value);
        $this->assertCommerce($integration);

        return response()->json(['data' => $health->for($integration)]);
    }

    public function sync(Request $request, TenantIntegration $integration): JsonResponse
    {
        Gate::authorize(Permission::IntegrationsManage->value);
        $this->assertCommerce($integration);

        $data = $request->validate([
            'entities' => ['sometimes', 'array', 'min:1'],
            'entities.*' => ['string', Rule::enum(SyncEntity::class)],
            'mode' => ['sometimes', 'string', Rule::enum(SyncMode::class)],
        ]);

        if (! in_array($integration->status, ['connected', 'syncing', 'error'], true)) {
            return response()->json(['message' => 'Test the connection successfully before syncing.', 'status' => $integration->status], 409);
        }

        $entities = array_map(fn (string $e) => SyncEntity::from($e), $data['entities'] ?? array_map(fn ($e) => $e->value, SyncEntity::ordered()));
        $mode = SyncMode::from($data['mode'] ?? SyncMode::Delta->value);

        foreach ($entities as $entity) {
            SyncCommerceEntity::dispatch($integration->id, $entity, $mode, $this->context->requireTenant());
        }

        return response()->json(['status' => 'sync_queued', 'entities' => array_map(fn ($e) => $e->value, $entities), 'mode' => $mode->value], 202);
    }

    public function preview(TenantIntegration $integration, DataPreview $preview): JsonResponse
    {
        Gate::authorize(Permission::CustomersView->value);
        $this->assertCommerce($integration);

        return response()->json(['data' => $preview->sample()]);
    }

    public function pushMetrics(TenantIntegration $integration, PushMetrics $metrics): JsonResponse
    {
        Gate::authorize(Permission::IntegrationsView->value);
        if ($integration->provider !== 'onesignal') {
            throw ValidationException::withMessages(['integration' => 'Not a OneSignal integration.']);
        }

        return response()->json(['data' => $metrics->for($integration)]);
    }

    private function assertCommerce(TenantIntegration $integration): void
    {
        if ($integration->capability !== 'commerce') {
            throw ValidationException::withMessages(['integration' => 'Not a commerce integration.']);
        }
    }
}
