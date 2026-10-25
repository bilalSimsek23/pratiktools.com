<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Actor;
use App\Email\EmailMessageType;
use App\Email\Templates\EmailTemplates;
use App\Email\Templates\TemplateError;
use App\Http\Controllers\Controller;
use App\Tenancy\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * E-mail templates (F4 model): drafts, immutable published versions,
 * archive, and a render preview through the same pipeline as sending.
 * The preview calls no provider and sends nothing (there is no test send
 * in F6). Sender identities are listed for campaign selection.
 */
final class ContentController extends Controller
{
    public function templates(): JsonResponse
    {
        Gate::authorize(Permission::CampaignsView->value);

        return response()->json(['data' => DB::table('email_templates')->orderBy('name')->get(['id', 'name', 'message_type', 'status', 'current_version', 'updated_at'])
            ->map(fn ($t) => (array) $t + ['versions' => DB::table('email_template_versions')->where('template_id', $t->id)->orderByDesc('version')
                ->get(['id', 'version', 'status', 'published_at', 'created_at'])])]);
    }

    public function createTemplate(Request $request, EmailTemplates $templates): JsonResponse
    {
        Gate::authorize(Permission::ContentManage->value);
        $data = $request->validate(['name' => 'required|string|max:120', 'message_type' => 'required|in:marketing,transactional']);

        return $this->guarded(fn () => response()->json(['data' => ['id' => $templates->create($data['name'], EmailMessageType::from($data['message_type']), $this->actor($request))]], 201));
    }

    public function addVersion(Request $request, string $template, EmailTemplates $templates): JsonResponse
    {
        Gate::authorize(Permission::ContentManage->value);
        $data = $request->validate(['subject' => 'required|string|max:400', 'preheader' => 'nullable|string|max:200', 'html' => 'nullable|string|max:524288',
            'text' => 'required|string|max:262144', 'metadata' => 'nullable|array']);

        return $this->guarded(fn () => response()->json(['data' => ['id' => $templates->addVersion($template, $data['subject'], $data['html'] ?? null, $data['text'],
            $this->actor($request), $data['preheader'] ?? null, array_filter((array) ($data['metadata'] ?? []), 'is_scalar'))]], 201));
    }

    public function publish(Request $request, string $version, EmailTemplates $templates): JsonResponse
    {
        Gate::authorize(Permission::ContentPublish->value);

        return $this->guarded(function () use ($request, $version, $templates) {
            $templates->publish($version, $this->actor($request));

            return response()->json(['data' => ['id' => $version, 'status' => 'published']]);
        });
    }

    public function archive(Request $request, string $template, EmailTemplates $templates): JsonResponse
    {
        Gate::authorize(Permission::ContentPublish->value);

        return $this->guarded(function () use ($request, $template, $templates) {
            $templates->archive($template, $this->actor($request));

            return response()->json(['data' => ['id' => $template, 'status' => 'archived']]);
        });
    }

    public function preview(string $version, EmailTemplates $templates): JsonResponse
    {
        Gate::authorize(Permission::CampaignsView->value);

        return $this->guarded(function () use ($version, $templates) {
            $v = $templates->version($version);
            $rendered = $templates->render($v, EmailTemplates::sampleValues());

            return response()->json(['data' => $rendered + ['status' => $v->status, 'viewports' => ['desktop' => 640, 'mobile' => 375],
                'note' => 'Render preview with sample values; nothing is sent.']]);
        });
    }

    public function senders(): JsonResponse
    {
        Gate::authorize(Permission::CampaignsView->value);

        return response()->json(['data' => DB::table('email_sender_identities as s')->join('tenant_integrations as i', fn ($j) => $j->on('i.id', '=', 's.integration_id')->on('i.tenant_id', '=', 's.tenant_id'))
            ->get(['s.id', 's.integration_id', 's.provider', 's.from_email', 's.from_name', 's.domain', 's.verification_status', 's.dkim_status', 's.mail_from_status', 's.last_checked_at',
                DB::raw("i.settings->>'region' AS region")])]);
    }

    private function guarded(\Closure $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (TemplateError $e) {
            return response()->json(['error' => $e->reason, 'message' => $e->getMessage()], $e->reason === 'NOT_FOUND' ? 404 : 422);
        }
    }

    private function actor(Request $request): Actor
    {
        return Actor::user((string) $request->user()?->getAuthIdentifier());
    }
}
