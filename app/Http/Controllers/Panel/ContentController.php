<?php

namespace App\Http\Controllers\Panel;

use App\Email\EmailMessageType;
use App\Email\Templates\EmailHtmlPolicy;
use App\Email\Templates\EmailTemplates;
use App\Email\Templates\TemplateError;
use App\Email\Templates\TemplateRenderer;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\PanelHelpers;
use App\Tenancy\Permission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class ContentController extends Controller
{
    use PanelHelpers;

    public function index(): View
    {
        $this->authorizePanel(Permission::CampaignsView);

        return view('panel.templates.index', ['templates' => DB::table('email_templates as t')->orderBy('t.name')
            ->get(['t.*', DB::raw('(SELECT count(*) FROM email_template_versions v WHERE v.template_id = t.id) AS versions')])]);
    }

    public function store(Request $request, EmailTemplates $templates): RedirectResponse
    {
        $this->authorizePanel(Permission::ContentManage);
        $data = $request->validate(['name' => 'required|string|max:120', 'message_type' => 'required|in:marketing,transactional']);
        try {
            $id = $templates->create($data['name'], EmailMessageType::from($data['message_type']), $this->actor());
        } catch (TemplateError $e) {
            return $this->failed($e->getMessage());
        }

        return redirect()->route('panel.templates.show', $id);
    }

    public function show(Request $request, string $template, EmailTemplates $templates, EmailHtmlPolicy $policy, TemplateRenderer $renderer): View
    {
        $this->authorizePanel(Permission::CampaignsView);
        $t = DB::table('email_templates')->where('id', $template)->first() ?? abort(404);
        $versions = DB::table('email_template_versions')->where('template_id', $t->id)->orderByDesc('version')->get();
        $selected = $versions->firstWhere('id', $request->query('version')) ?? $versions->first();
        $safety = null;
        if ($selected !== null) {
            $issues = $selected->html_template === null ? [] : $policy->issues($selected->html_template);
            try {
                $renderer->variables($selected->subject_template.' '.$selected->text_template.' '.$selected->html_template);
                $syntax = null;
            } catch (TemplateError $e) {
                $syntax = $e->getMessage();
            }
            $safety = ['issues' => $issues, 'syntax' => $syntax, 'rendered' => rescue(fn () => $templates->render($templates->version($selected->id), EmailTemplates::sampleValues()), null, false)];
        }

        return view('panel.templates.show', ['t' => $t, 'versions' => $versions, 'v' => $selected, 'safety' => $safety,
            'variables' => (array) config('email.templates.variables'), 'canEdit' => $this->can(Permission::ContentManage), 'canPublish' => $this->can(Permission::ContentPublish)]);
    }

    public function storeVersion(Request $request, string $template, EmailTemplates $templates): RedirectResponse
    {
        $this->authorizePanel(Permission::ContentManage);
        $data = $request->validate(['subject' => 'required|string|max:400', 'preheader' => 'nullable|string|max:200', 'html' => 'nullable|string|max:524288', 'text' => 'required|string|max:262144']);
        try {
            $id = $templates->addVersion($template, $data['subject'], ($data['html'] ?? '') === '' ? null : $data['html'], $data['text'], $this->actor(), $data['preheader'] ?? null);
        } catch (TemplateError $e) {
            return $this->failed("İçerik kaydedilemedi ({$e->reason}): {$e->getMessage()}");
        }

        return redirect()->route('panel.templates.show', ['template' => $template, 'version' => $id])->with('status', 'Taslak versiyon kaydedildi.');
    }

    public function publish(string $version, EmailTemplates $templates): RedirectResponse
    {
        $this->authorizePanel(Permission::ContentPublish);
        try {
            $templates->publish($version, $this->actor());
        } catch (TemplateError $e) {
            return $this->failed("Yayınlanamadı ({$e->reason}): {$e->getMessage()}");
        }

        return $this->done('Versiyon yayınlandı; artık değiştirilemez.');
    }

    public function archive(string $template, EmailTemplates $templates): RedirectResponse
    {
        $this->authorizePanel(Permission::ContentPublish);
        try {
            $templates->archive($template, $this->actor());
        } catch (TemplateError $e) {
            return $this->failed($e->getMessage());
        }

        return $this->done('Şablon arşivlendi.');
    }

    /**
     * Sanitized render for a sandboxed iframe (sample values, no provider).
     */
    public function frame(string $version, EmailTemplates $templates): Response
    {
        $this->authorizePanel(Permission::CampaignsView);
        try {
            $rendered = $templates->render($templates->version($version), EmailTemplates::sampleValues());
        } catch (TemplateError) {
            abort(404);
        }
        $html = $rendered['html'] ?? '<pre>'.e($rendered['text']).'</pre>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'X-Panel-Preview-Frame' => '1', 'Cache-Control' => 'no-store']);
    }

    public function senders(): View
    {
        $this->authorizePanel(Permission::CampaignsView);

        return view('panel.senders', ['senders' => DB::table('email_sender_identities as s')->join('tenant_integrations as i', 'i.id', '=', 's.integration_id')
            ->orderBy('s.from_email')->get(['s.*', 'i.provider', DB::raw("i.settings->>'region' AS region")])]);
    }
}
