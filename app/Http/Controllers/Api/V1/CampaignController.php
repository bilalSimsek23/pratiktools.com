<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Actor;
use App\Campaigns\CampaignAudience;
use App\Campaigns\CampaignDryRun;
use App\Campaigns\CampaignError;
use App\Campaigns\CampaignReadiness;
use App\Campaigns\Campaigns;
use App\Campaigns\CampaignStatus;
use App\Http\Controllers\Controller;
use App\Tenancy\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Campaigns: drafts, audience/content/sender selection, readiness, audience
 * preview and dry-run. No endpoint sends or dispatches anything.
 */
final class CampaignController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize(Permission::CampaignsView->value);

        return response()->json(['data' => DB::table('campaigns')->orderByDesc('created_at')
            ->get(['id', 'name', 'type', 'channel', 'status', 'audience_source', 'segment_id', 'segment_snapshot_id', 'template_version_id', 'sender_identity_id', 'scheduled_at', 'frozen_at', 'created_at'])]);
    }

    public function show(string $campaign, Campaigns $campaigns, CampaignReadiness $readiness): JsonResponse
    {
        Gate::authorize(Permission::CampaignsView->value);
        $row = $this->find($campaigns, $campaign);

        return response()->json(['data' => (array) $row + ['readiness' => $readiness->evaluate($row)]]);
    }

    public function store(Request $request, Campaigns $campaigns): JsonResponse
    {
        Gate::authorize(Permission::CampaignsManage->value);

        return $this->guarded(fn () => response()->json(['data' => ['id' => $campaigns->create($this->attributes($request), $this->actor($request))]], 201));
    }

    public function update(Request $request, string $campaign, Campaigns $campaigns): JsonResponse
    {
        Gate::authorize(Permission::CampaignsManage->value);

        return $this->guarded(function () use ($request, $campaign, $campaigns) {
            $campaigns->update($campaign, $this->attributes($request), $this->actor($request));

            return response()->json(['data' => ['id' => $campaign]]);
        });
    }

    public function transition(Request $request, string $campaign, Campaigns $campaigns): JsonResponse
    {
        Gate::authorize(Permission::CampaignsPublish->value);
        $data = $request->validate(['to' => 'required|string|in:ready,scheduled,paused,cancelled,archived', 'scheduled_at' => 'nullable|date']);

        return $this->guarded(fn () => response()->json(['data' => $campaigns->transition($campaign, CampaignStatus::from($data['to']), $this->actor($request),
            isset($data['scheduled_at']) ? CarbonImmutable::parse($data['scheduled_at']) : null)]));
    }

    public function audience(string $campaign, Campaigns $campaigns, CampaignAudience $audience): JsonResponse
    {
        Gate::authorize(Permission::CampaignsView->value);

        return $this->guarded(fn () => response()->json(['data' => $audience->report($this->find($campaigns, $campaign))]));
    }

    public function dryRun(Request $request, string $campaign, Campaigns $campaigns, CampaignDryRun $dryRun): JsonResponse
    {
        Gate::authorize(Permission::CampaignsManage->value);
        $this->find($campaigns, $campaign);

        return $this->guarded(fn () => response()->json(['data' => $dryRun->run($campaign, $this->actor($request))]));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:160', 'type' => 'required|string', 'audience_source' => 'nullable|string', 'segment_id' => 'nullable|uuid',
            'segment_snapshot_id' => 'nullable|uuid', 'template_version_id' => 'nullable|uuid', 'integration_id' => 'nullable|uuid', 'sender_identity_id' => 'nullable|uuid',
            'settings' => 'nullable|array',
        ]);
    }

    private function find(Campaigns $campaigns, string $id): object
    {
        try {
            return $campaigns->find($id);
        } catch (CampaignError) {
            abort(404);
        }
    }

    private function guarded(\Closure $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (CampaignError $e) {
            return response()->json(['error' => $e->reason, 'message' => $e->getMessage(), 'codes' => $e->codes], $e->reason === 'NOT_FOUND' ? 404 : 422);
        }
    }

    private function actor(Request $request): Actor
    {
        return Actor::user((string) $request->user()?->getAuthIdentifier());
    }
}
