<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\PanelHelpers;
use App\Site\ContentStatus;
use App\Site\SiteError;
use App\Site\SiteMedia;
use App\Site\SiteReferences;
use App\Tenancy\Permission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Web Sitesi › Referanslar: full CRUD without code or deploy. Texts,
 * relations and order (edit), logos (manage_logo), publish/archive/delete
 * (publish) and client approvals (approve_client) are separate permissions.
 */
final class ReferenceController extends Controller
{
    use PanelHelpers;

    public function index(): View
    {
        $this->authorizePanel(Permission::SiteReferencesView);

        return view('panel.website.references.index', [
            'references' => DB::table('site_references')->orderBy('featured_order')->orderBy('name')->get(),
            'canEdit' => $this->can(Permission::SiteReferencesEdit),
        ]);
    }

    public function store(Request $request, SiteReferences $references): RedirectResponse
    {
        $this->authorizePanel(Permission::SiteReferencesEdit);
        try {
            $id = $references->create((string) $request->validate(['name' => 'required|string|max:160'])['name'], $this->actor());
        } catch (SiteError $e) {
            return $this->failed($e->getMessage());
        }

        return redirect()->route('panel.website.references.edit', $id)->with('status', 'Referans taslağı oluşturuldu.');
    }

    public function arrange(Request $request, SiteReferences $references): RedirectResponse
    {
        $this->authorizePanel(Permission::SiteReferencesEdit);
        $d = $request->validate(['rows' => 'required|array|max:500', 'rows.*.order' => 'nullable|integer|min:-1000|max:1000', 'rows.*.featured' => 'nullable|boolean']);
        $references->arrange(array_filter($d['rows'], fn ($k) => is_string($k) && Str::isUuid($k), ARRAY_FILTER_USE_KEY), $this->actor());

        return $this->done('Sıralama ve ana sayfa seçimi kaydedildi.');
    }

    public function edit(string $reference, SiteReferences $references): View
    {
        $this->authorizePanel(Permission::SiteReferencesView);
        $row = $this->row($reference, $references);

        return view('panel.website.references.edit', ['r' => $row, 'capabilities' => (array) json_decode((string) $row->capabilities, true),
            'linked' => DB::table('site_reference_contents')->where('reference_id', $row->id)->orderBy('position')->pluck('content_id')->all(),
            'contents' => DB::table('site_contents')->whereIn('type', ['project', 'service', 'solution'])->orderBy('type')->orderBy('title')->get(['id', 'type', 'title', 'status']),
            'logo' => SiteMedia::describe($row->logo_media_id), 'logoDark' => SiteMedia::describe($row->logo_dark_media_id),
            'can' => ['edit' => $this->can(Permission::SiteReferencesEdit), 'publish' => $this->can(Permission::SiteReferencesPublish),
                'approve' => $this->can(Permission::SiteReferencesApproveClient), 'logo' => $this->can(Permission::SiteReferencesManageLogo)]]);
    }

    public function update(Request $request, string $reference, SiteReferences $references): RedirectResponse
    {
        $this->authorizePanel(Permission::SiteReferencesEdit);
        $d = $request->validate(['name' => 'required|string|max:160', 'slug' => 'required|string|max:140', 'website_url' => 'nullable|string|max:300', 'industry' => 'nullable|string|max:120',
            'short_description' => 'nullable|string|max:300', 'body' => 'nullable|string|max:20000', 'capabilities' => 'nullable|string|max:3000',
            'contents' => 'nullable|array|max:20', 'contents.*' => 'uuid', 'active' => 'nullable|boolean']);
        try {
            $references->update($this->row($reference, $references)->id, $d + ['contents' => $d['contents'] ?? [], 'active' => $request->boolean('active')], $this->actor());
        } catch (SiteError $e) {
            return $this->failed($e->getMessage());
        }

        return $this->done('Kaydedildi.');
    }

    public function status(Request $request, string $reference, SiteReferences $references): RedirectResponse
    {
        $this->authorizePanel(Permission::SiteReferencesPublish);
        $status = ContentStatus::from((string) $request->validate(['status' => 'required|in:draft,published,archived'])['status']);
        try {
            $references->setStatus($this->row($reference, $references)->id, $status, $this->actor());
        } catch (SiteError $e) {
            return $this->failed($e->getMessage());
        }

        return $this->done('Durum: '.$status->label().'.');
    }

    public function destroy(string $reference, SiteReferences $references): RedirectResponse
    {
        $this->authorizePanel(Permission::SiteReferencesPublish);
        try {
            $references->delete($this->row($reference, $references)->id, $this->actor());
        } catch (SiteError $e) {
            return $this->failed($e->getMessage());
        }

        return redirect()->route('panel.website.references')->with('status', 'Referans silindi.');
    }

    public function approval(Request $request, string $reference, SiteReferences $references): RedirectResponse
    {
        $this->authorizePanel(Permission::SiteReferencesApproveClient);
        $d = $request->validate(['what' => 'required|in:name,logo', 'approved' => 'required|boolean', 'note' => 'nullable|string|max:255', 'confirm' => 'nullable|boolean']);
        if ($d['approved'] && ! $request->boolean('confirm')) {
            return $this->failed('Onayın müşteriden alındığını doğrulayan kutuyu işaretleyin.');
        }
        try {
            $references->approve($this->row($reference, $references)->id, $d['what'], (bool) $d['approved'], (string) ($d['note'] ?? ''), $this->actor());
        } catch (SiteError $e) {
            return $this->failed($e->getMessage());
        }

        return $this->done('Onay durumu güncellendi.');
    }

    public function logo(Request $request, string $reference, SiteReferences $references): RedirectResponse
    {
        $this->authorizePanel(Permission::SiteReferencesManageLogo);
        $d = $request->validate(['variant' => 'required|in:light,dark', 'remove' => 'nullable|boolean',
            'logo' => 'nullable|required_unless:remove,1|file|max:'.(int) (config('site.media_max_bytes') / 1024), 'alt' => 'nullable|required_unless:remove,1|string|max:200']);
        try {
            $references->setLogo($this->row($reference, $references)->id, $d['variant'], $request->boolean('remove') ? null : $request->file('logo'), (string) ($d['alt'] ?? ''), $this->actor());
        } catch (SiteError $e) {
            return $this->failed($e->getMessage());
        }

        return $this->done($request->boolean('remove') ? 'Logo kaldırıldı.' : 'Logo yüklendi. Yayında görünmesi için logo kullanım onayı gerekir.');
    }

    private function row(string $id, SiteReferences $references): object
    {
        try {
            return $references->find($id);
        } catch (SiteError) {
            abort(404);
        }
    }
}
