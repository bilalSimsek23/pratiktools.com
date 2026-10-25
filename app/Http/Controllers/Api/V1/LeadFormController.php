<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Actor;
use App\Http\Controllers\Controller;
use App\Leads\LeadError;
use App\Leads\LeadForms;
use App\Leads\LeadReport;
use App\Tenancy\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class LeadFormController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize(Permission::LeadsView->value);

        return response()->json(['data' => DB::table('lead_forms')->orderBy('name')
            ->get(['id', 'public_id', 'name', 'placement', 'active', 'consent_version', 'consent_text', 'success_message', 'locale', 'tags', 'allowed_origins', 'double_opt_in', 'updated_at'])]);
    }

    public function store(Request $request, LeadForms $forms): JsonResponse
    {
        Gate::authorize(Permission::FormsManage->value);
        $d = $request->validate(['name' => 'required|string|max:120', 'placement' => 'required|string', 'consent_text' => 'required|string|max:4000',
            'success_message' => 'required|string|max:300', 'allowed_origins' => 'required|array|min:1', 'allowed_origins.*' => 'string', 'locale' => 'nullable|string|max:8',
            'tags' => 'nullable|array', 'double_opt_in' => 'nullable|boolean']);

        return $this->guarded(fn () => response()->json(['data' => ['id' => $forms->create($d['name'], $d['placement'], $d['consent_text'], $d['success_message'],
            $d['allowed_origins'], $this->actor($request), $d['locale'] ?? 'tr', $d['tags'] ?? [], (bool) ($d['double_opt_in'] ?? false))]], 201));
    }

    public function setActive(Request $request, string $form, LeadForms $forms): JsonResponse
    {
        Gate::authorize(Permission::FormsManage->value);
        $active = $request->validate(['active' => 'required|boolean'])['active'];

        return $this->guarded(function () use ($request, $form, $forms, $active) {
            $forms->setActive($form, (bool) $active, $this->actor($request));

            return response()->json(['data' => ['id' => $form, 'active' => (bool) $active]]);
        });
    }

    public function consentText(Request $request, string $form, LeadForms $forms): JsonResponse
    {
        Gate::authorize(Permission::FormsManage->value);
        $text = $request->validate(['consent_text' => 'required|string|max:4000'])['consent_text'];

        return $this->guarded(fn () => response()->json(['data' => ['id' => $form, 'consent_version' => $forms->changeConsentText($form, $text, $this->actor($request))]]));
    }

    public function report(Request $request, LeadReport $report): JsonResponse
    {
        Gate::authorize(Permission::LeadsView->value);

        return response()->json(['data' => $report->report((int) $request->query('days', '30'), $request->query('form'))]);
    }

    private function guarded(\Closure $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (LeadError $e) {
            return response()->json(['error' => $e->reason, 'message' => $e->getMessage()], $e->reason === 'NOT_FOUND' ? 404 : 422);
        }
    }

    private function actor(Request $request): Actor
    {
        return Actor::user((string) $request->user()?->getAuthIdentifier());
    }
}
