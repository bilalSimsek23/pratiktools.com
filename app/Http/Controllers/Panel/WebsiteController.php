<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\PanelHelpers;
use App\Http\Controllers\Site\PublicSiteController;
use App\Site\ContentStatus;
use App\Site\ContentType;
use App\Site\SiteCategories;
use App\Site\SiteContents;
use App\Site\SiteError;
use App\Site\SiteInquiries;
use App\Site\SiteMarkdown;
use App\Site\SiteMedia;
use App\Site\SiteQuery;
use App\Site\SiteSeo;
use App\Site\SiteSettings;
use App\Tenancy\Permission;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Web Sitesi: public website management for members of the site-owner
 * tenant (EnsureSiteOwner). Editing creates drafts; publishing, archiving
 * and client approvals need site.publish and a recent password check.
 */
final class WebsiteController extends Controller
{
    use PanelHelpers;

    public function index(string $type): View
    {
        $this->authorizePanel(Permission::SiteView);
        $t = ContentType::from($type);

        return view('panel.website.index', ['type' => $t, 'canEdit' => $this->can(Permission::SiteEdit),
            'items' => DB::table('site_contents')->where('type', $t->value)->orderBy('sort_order')->orderBy('title')->get(['id', 'slug', 'title', 'status', 'featured', 'updated_at', 'published_at']),
            'categories' => $t->categoryKind() === null ? collect() : DB::table('site_categories')->where('kind', $t->categoryKind())->orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function store(Request $request, string $type, SiteContents $contents): RedirectResponse
    {
        $this->authorizePanel(Permission::SiteEdit);
        try {
            $id = $contents->create(ContentType::from($type), (string) $request->validate(['title' => 'required|string|max:200'])['title'], $this->actor());
        } catch (SiteError $e) {
            return $this->failed($e->getMessage());
        }

        return redirect()->route('panel.website.edit', $id)->with('status', 'Taslak oluşturuldu.');
    }

    public function edit(string $content, SiteContents $contents): View
    {
        $this->authorizePanel(Permission::SiteView);
        $row = $this->row($content, $contents);
        $type = ContentType::from($row->type);

        return view('panel.website.edit', ['c' => $row, 'type' => $type, 'data' => (array) json_decode((string) $row->data, true),
            'categories' => $type->categoryKind() === null ? collect() : DB::table('site_categories')->where('kind', $type->categoryKind())->orderBy('sort_order')->get(['id', 'name']),
            'media' => DB::table('site_media')->orderByDesc('created_at')->limit(100)->get(['id', 'alt', 'width', 'height']),
            'relatable' => DB::table('site_contents')->where('id', '<>', $row->id)->whereIn('type', ['service', 'solution', 'project', 'article'])->orderBy('type')->orderBy('title')->get(['id', 'type', 'title', 'status']),
            'related' => DB::table('site_content_relations')->where('content_id', $row->id)->pluck('related_id')->all(),
            'publicUrl' => SiteQuery::url($row->type, $row->slug),
            'canEdit' => $this->can(Permission::SiteEdit), 'canPublish' => $this->can(Permission::SitePublish)]);
    }

    public function update(Request $request, string $content, SiteContents $contents): RedirectResponse
    {
        $this->authorizePanel(Permission::SiteEdit);
        $row = $this->row($content, $contents);
        $type = ContentType::from($row->type);
        $fields = collect($type->fields())->mapWithKeys(fn ($f) => [$f['key'] => $f['kind'] === 'bool' ? 'nullable|boolean' : 'nullable|string|max:20000'])->all();
        $input = $request->validate($fields + ['title' => 'required|string|max:200', 'slug' => 'required|string|max:140', 'excerpt' => 'nullable|string|max:600',
            'body' => 'nullable|string|max:60000', 'seo_title' => 'nullable|string|max:70', 'seo_description' => 'nullable|string|max:170', 'category_id' => 'nullable|uuid',
            'social_image_id' => 'nullable|uuid', 'featured' => 'nullable|boolean', 'sort_order' => 'nullable|integer|min:-1000|max:1000', 'related' => 'nullable|array|max:30', 'related.*' => 'uuid']);
        try {
            $contents->update($row->id, $input + ['related' => $input['related'] ?? [], 'featured' => $request->boolean('featured')], $this->actor());
        } catch (SiteError $e) {
            return $this->failed($e->getMessage());
        }

        return $this->done('Kaydedildi.'.($row->status === ContentStatus::Published->value ? ' Değişiklik yayındaki sayfaya yansıdı.' : ''));
    }

    public function status(Request $request, string $content, SiteContents $contents): RedirectResponse
    {
        $this->authorizePanel(Permission::SitePublish);
        $status = ContentStatus::from((string) $request->validate(['status' => 'required|in:draft,published,archived'])['status']);
        try {
            $contents->setStatus($this->row($content, $contents)->id, $status, $this->actor());
        } catch (SiteError $e) {
            return $this->failed($e->getMessage());
        }

        return $this->done('Durum: '.$status->label().'.');
    }

    public function clientApproval(Request $request, string $content, SiteContents $contents): RedirectResponse
    {
        $this->authorizePanel(Permission::SitePublish);
        $d = $request->validate(['note' => 'required|string|max:255', 'confirm' => 'accepted']);
        try {
            $contents->approveClient($this->row($content, $contents)->id, $d['note'], $this->actor());
        } catch (SiteError $e) {
            return $this->failed($e->getMessage());
        }

        return $this->done('Müşteri onayı kaydedildi.');
    }

    /**
     * Draft preview with the public templates; never indexed (X-Robots-Tag on /app).
     */
    public function preview(string $content, SiteQuery $query, PublicSiteController $site, SiteSeo $seo): Response
    {
        $this->authorizePanel(Permission::SiteView);
        $item = $query->preview($content) ?? abort(404);
        $type = ContentType::from($item['type']);
        $meta = $seo->meta(($item['seo_title'] ?? null) ?: $item['title'], $item['excerpt'], $item['url'], index: false);
        $crumbs = [['Ana Sayfa', '/'], [$item['title'], $item['url']]];
        $view = match (true) {
            $type === ContentType::Page && $item['slug'] === 'iletisim' => 'site.contact',
            $type === ContentType::Page && $item['slug'] === 'home' => null,
            $type === ContentType::Page => 'site.page',
            default => 'site.show',
        };
        if ($view === null) {
            return $site->home()->header('Cache-Control', 'no-store');
        }

        return $site->render($view, ['type' => $type, 'item' => $item, 'html' => SiteMarkdown::render((string) $item['body']), 'crumbs' => $crumbs, 'otherProjects' => []],
            $meta, [], null)->header('Cache-Control', 'no-store');
    }

    public function saveCategory(Request $request, string $kind, SiteCategories $categories): RedirectResponse
    {
        $this->authorizePanel(Permission::SiteEdit);
        $d = $request->validate(['id' => 'nullable|uuid', 'name' => 'required|string|max:120', 'slug' => 'nullable|string|max:120', 'description' => 'nullable|string|max:1000',
            'sort_order' => 'nullable|integer|min:-1000|max:1000', 'active' => 'nullable|boolean']);
        try {
            $categories->save($kind, $d + ['active' => $request->boolean('active')], $this->actor(), $d['id'] ?? null);
        } catch (SiteError $e) {
            return $this->failed($e->getMessage());
        }

        return $this->done('Kategori kaydedildi.');
    }

    public function media(): View
    {
        $this->authorizePanel(Permission::SiteView);

        return view('panel.website.media', ['media' => DB::table('site_media')->orderByDesc('created_at')->limit(200)->get(), 'canEdit' => $this->can(Permission::SiteEdit),
            'ephemeral' => app()->isProduction() && config('site.media_disk') === 'local']);
    }

    public function upload(Request $request, SiteMedia $media): RedirectResponse
    {
        $this->authorizePanel(Permission::SiteEdit);
        $d = $request->validate(['image' => 'required|file|max:'.(int) (config('site.media_max_bytes') / 1024), 'alt' => 'required|string|max:200']);
        try {
            $media->upload($request->file('image'), $d['alt'], $this->actor());
        } catch (SiteError $e) {
            return $this->failed($e->getMessage());
        }

        return $this->done('Görsel yüklendi.');
    }

    public function leads(Request $request): View
    {
        $this->authorizePanel(Permission::SiteLeads);
        $status = in_array($request->query('durum'), ['new', 'contacted', 'closed'], true) ? (string) $request->query('durum') : null;

        return view('panel.website.leads', ['status' => $status,
            'counts' => DB::table('lead_inquiries')->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')->all(),
            'sources' => DB::table('lead_inquiries')->where('created_at', '>=', now()->subDays(30))->selectRaw("attribution->'last'->>'class' AS c, count(*) AS n")->groupBy('c')->orderByDesc('n')->pluck('n', 'c')->all(),
            'inquiries' => DB::table('lead_inquiries as i')->leftJoin('consent_recipients as r', fn ($j) => $j->on('r.id', '=', 'i.recipient_id')->on('r.tenant_id', '=', 'i.tenant_id'))
                ->when($status !== null, fn ($q) => $q->where('i.status', $status))->orderByDesc('i.created_at')->limit(100)
                ->get(['i.id', 'i.kind', 'i.name', 'i.phone', 'i.company', 'i.interest', 'i.message', 'i.attribution', 'i.status', 'i.created_at', 'r.address as email'])]);
    }

    public function leadStatus(Request $request, string $inquiry, SiteInquiries $inquiries): RedirectResponse
    {
        $this->authorizePanel(Permission::SiteLeads);
        try {
            $inquiries->setStatus($inquiry, (string) $request->validate(['status' => 'required|in:new,contacted,closed'])['status'], $this->actor());
        } catch (SiteError $e) {
            return $this->failed($e->getMessage());
        }

        return $this->done('Talep güncellendi.');
    }

    public function seo(): View
    {
        $this->authorizePanel(Permission::SiteView);
        $rows = DB::table('site_contents')->where('status', ContentStatus::Published->value)->orderBy('type')->orderBy('title')
            ->get(['id', 'type', 'slug', 'title', 'excerpt', 'seo_title', 'seo_description', 'social_image_id', 'data']);

        return view('panel.website.seo', [
            'statusCounts' => DB::table('site_contents')->selectRaw('type, status, count(*) AS n')->groupBy('type', 'status')->get(),
            'issues' => $rows->map(fn ($r) => ['r' => $r, 'url' => SiteQuery::url($r->type, $r->slug), 'issues' => array_values(array_filter([
                $r->seo_description === null && mb_strlen((string) $r->excerpt) < 70 ? 'Meta açıklama yok veya özet çok kısa' : null,
                $r->seo_title === null && mb_strlen($r->title) > 60 ? 'Başlık 60 karakterden uzun; SEO başlığı girin' : null,
                $r->social_image_id === null && $r->type !== 'page' ? 'Paylaşım görseli yok' : null,
                in_array($r->type, ['service', 'solution'], true) && empty(json_decode((string) $r->data, true)['faq'] ?? []) ? 'SSS yok' : null,
            ]))])->filter(fn ($x) => $x['issues'] !== [])->values(),
            'events' => DB::table('site_events_daily')->where('day', '>=', now()->subDays(30)->toDateString())->selectRaw('event, sum(count) AS n')->groupBy('event')->pluck('n', 'event')->all(),
            'topPaths' => DB::table('site_events_daily')->where('day', '>=', now()->subDays(30)->toDateString())->where('event', 'page_view')
                ->selectRaw('path, sum(count) AS n')->groupBy('path')->orderByDesc('n')->limit(15)->get(),
            'crawlers' => app(SiteSettings::class)->get('crawlers'),
        ]);
    }

    public function settings(SiteSettings $settings, SiteInquiries $inquiries, TenantContext $context): View
    {
        $this->authorizePanel(Permission::SiteView);
        $form = json_decode((string) DB::table('site_settings')->where('key', 'lead_form')->value('value'), true)['public_id'] ?? config('site.lead_form');

        return view('panel.website.settings', ['org' => $settings->get('organization'), 'analytics' => $settings->get('analytics'), 'crawlers' => $settings->get('crawlers'),
            'canSettings' => $this->can(Permission::SiteSettings), 'inquiryAvailable' => $inquiries->available(),
            'form' => $form === null ? null : DB::table('lead_forms')->where('tenant_id', $context->requireTenant())->where('public_id', $form)->first(['id', 'name', 'active', 'allowed_origins']),
            'origin' => SiteInquiries::origin()]);
    }

    public function saveSettings(Request $request, string $group, SiteSettings $settings): RedirectResponse
    {
        $this->authorizePanel(Permission::SiteSettings);
        try {
            $settings->set($group, $request->except('_token'), $this->actor());
        } catch (SiteError $e) {
            return $this->failed($e->getMessage());
        }

        return $this->done('Ayarlar kaydedildi.');
    }

    private function row(string $id, SiteContents $contents): object
    {
        try {
            return $contents->find($id);
        } catch (SiteError) {
            abort(404);
        }
    }
}
