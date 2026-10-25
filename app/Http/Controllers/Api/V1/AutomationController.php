<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Actor;
use App\Automation\AutomationMetrics;
use App\Automation\DryRun;
use App\Http\Controllers\Controller;
use App\Tenancy\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Minimal automation management surface (read + dry-run). Runs are shown
 * by id, status, step and exit reason; no customer data.
 */
final class AutomationController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize(Permission::AutomationsView->value);

        return response()->json(['data' => DB::table('automations as a')
            ->leftJoin('automation_versions as v', fn ($j) => $j->on('v.id', '=', 'a.published_version_id')->on('v.tenant_id', '=', 'a.tenant_id'))
            ->orderBy('a.name')->get(['a.id', 'a.name', 'a.template', 'a.status', 'v.version as published_version', 'v.trigger', 'a.updated_at'])
            ->map(fn ($a) => ['trigger' => json_decode((string) $a->trigger, true)] + (array) $a)]);
    }

    public function show(string $automation, AutomationMetrics $metrics): JsonResponse
    {
        Gate::authorize(Permission::AutomationsView->value);
        $row = DB::table('automations')->where('id', $automation)->first() ?? abort(404);
        $versions = DB::table('automation_versions')->where('automation_id', $automation)->orderByDesc('version')
            ->get(['id', 'version', 'status', 'trigger', 'steps', 'settings', 'published_at', 'created_at'])
            ->map(fn ($v) => ['trigger' => json_decode($v->trigger, true), 'steps' => json_decode($v->steps, true), 'settings' => json_decode($v->settings, true)] + (array) $v);
        $runs = DB::table('automation_runs')->where('automation_id', $automation)->orderByDesc('started_at')->limit(25)
            ->get(['id', 'status', 'trigger_type', 'current_step', 'resume_at', 'exit_reason', 'started_at', 'completed_at']);

        return response()->json(['data' => (array) $row + ['versions' => $versions, 'last_runs' => $runs, 'metrics' => $metrics->report($automation)]]);
    }

    public function dryRun(Request $request, string $automation, DryRun $dryRun): JsonResponse
    {
        Gate::authorize(Permission::AutomationsManage->value);
        abort_unless(DB::table('automations')->where('id', $automation)->exists(), 404);
        $user = $request->user();

        return response()->json(['data' => $dryRun->run($automation, $user === null ? null : Actor::user((string) $user->getAuthIdentifier()), maxCandidates: 5000)]);
    }
}
