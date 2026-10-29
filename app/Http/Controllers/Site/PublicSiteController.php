<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Site\ContentType;
use App\Site\InitialContent;
use App\Site\SiteAnalytics;
use App\Site\SiteInquiries;
use App\Site\SiteMarkdown;
use App\Site\SiteQuery;
use App\Site\SiteSeo;
use App\Site\SiteSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The public PratikTools website: server-rendered, published content only,
 * no session or cookie, cacheable. Never sets a tenant context.
 */
final class PublicSiteController extends Controller
{
    public function __construct(
        private readonly SiteQuery $site,
        private readonly SiteSeo $seo,
        private readonly SiteSettings $settings,
        private readonly SiteAnalytics $analytics,
        private readonly SiteInquiries $inquiries,
    ) {}

    public function home(): Response
    {
        $page = $this->site->find(ContentType::Page, 'home') ?? InitialContent::fallbackHome();
        $services = $this->site->published(ContentType::Service);

        return $this->render('site.home', [
            'page' => $page,
            'categories' => array_map(fn ($c) => $c + ['services' => array_values(array_filter($services, fn ($s) => ($s['category']['id'] ?? null) === $c['id']))],
                $this->site->categories('service')),
            'solutions' => $this->site->published(ContentType::Solution, limit: 6),
            'projects' => $this->site->published(ContentType::Project, limit: 6, featuredOnly: true),
            'references' => $this->site->references(featuredOnly: true),
            'articles' => $this->site->published(ContentType::Article, limit: 3),
        ], $this->seo->meta($page['seo_title'] ?? 'PratikTools', $page['seo_description'] ?? $page['excerpt'], '/'), [$this->seo->organization(), $this->seo->website()], 'page_view');
    }

    public function index(Request $request, string $type): Response
    {
        $type = ContentType::from($type);
        $category = null;
        if ($type === ContentType::Article && is_string($request->query('kategori'))) {
            $category = collect($this->site->categories('article'))->firstWhere('slug', $request->query('kategori'));
        }
        $items = $this->site->published($type, $category['id'] ?? null);
        [$title, $intro] = match ($type) {
            ContentType::Service => ['Hizmetler', 'E-ticaret, entegrasyon, otomasyon ve yapay zeka alanlarında yaptığımız işler, kategorilere göre.'],
            ContentType::Solution => ['Çözümler', 'Belirli bir ihtiyaca odaklanan, uçtan uca çözüm sayfaları.'],
            ContentType::Project => ['Projeler', 'Gerçek projelerden örnekler. Müşteri izni olmayan projeler anonim olarak anlatılır.'],
            default => ['Blog', 'Ticimax, Nebim, entegrasyon, otomasyon, yapay zeka ve teknik SEO üzerine notlar.'],
        };
        $path = '/'.$type->routePrefix();

        return $this->render('site.index', ['type' => $type, 'title' => $title, 'intro' => $intro, 'items' => $items, 'category' => $category,
            'categories' => $type === ContentType::Service ? $this->site->categories('service') : ($type === ContentType::Article ? $this->site->categories('article') : []),
            'crumbs' => [['Ana Sayfa', '/'], [$title, $path]]],
            // Filtered blog lists are not separate pages for search engines.
            $this->seo->meta($category === null ? $title : $category['name'].' — '.$title, $intro, $path, index: $category === null),
            [$this->seo->organization(), $this->seo->breadcrumbs([['Ana Sayfa', '/'], [$title, $path]])], 'page_view');
    }

    public function show(string $slug, string $type): Response
    {
        $type = ContentType::from($type);
        $item = $this->site->find($type, $slug) ?? abort(404);
        $description = $item['seo_description'] ?: ($item['excerpt'] ?: SiteMarkdown::plain($item['body']));
        $listTitle = $type->plural();
        $crumbs = [['Ana Sayfa', '/'], [$listTitle, '/'.$type->routePrefix()], [$item['title'], $item['url']]];
        $faq = in_array($type, [ContentType::Service, ContentType::Solution], true) ? $this->seo->faq((array) ($item['data']['faq'] ?? [])) : null;
        $schema = match ($type) {
            ContentType::Service, ContentType::Solution => $this->seo->service($item, $description),
            ContentType::Article => $this->seo->article($item, $description),
            default => null,
        };

        return $this->render('site.show', ['type' => $type, 'item' => $item, 'html' => SiteMarkdown::render($item['body']), 'crumbs' => $crumbs,
            'otherProjects' => $type === ContentType::Project ? array_slice(array_filter($this->site->published(ContentType::Project), fn ($p) => $p['id'] !== $item['id']), 0, 3) : []],
            $this->seo->meta($item['seo_title'] ?: $item['title'], $description, $item['url'], image: $item['image'], ogType: $type === ContentType::Article ? 'article' : 'website'),
            [$this->seo->organization(), $schema, $faq, $this->seo->breadcrumbs($crumbs)], $type->value.'_view');
    }

    public function page(string $slug): Response
    {
        $item = $this->site->find(ContentType::Page, $slug) ?? InitialContent::fallbackPage($slug) ?? abort(404);
        $crumbs = [['Ana Sayfa', '/'], [$item['title'], $item['url']]];

        return $this->render($slug === 'iletisim' ? 'site.contact' : 'site.page', ['item' => $item, 'html' => SiteMarkdown::render((string) ($item['body'] ?? '')), 'crumbs' => $crumbs],
            $this->seo->meta(($item['seo_title'] ?? null) ?: $item['title'], ($item['seo_description'] ?? null) ?: $item['excerpt'], $item['url']),
            [$this->seo->organization(), $this->seo->breadcrumbs($crumbs)], 'page_view');
    }

    public function references(): Response
    {
        $crumbs = [['Ana Sayfa', '/'], ['Referanslar', '/referanslar']];
        $intro = 'Birlikte çalıştığımız kurumlar ve onlar için geliştirdiğimiz sistemler.';

        return $this->render('site.references', ['references' => $this->site->references(), 'crumbs' => $crumbs, 'intro' => $intro],
            $this->seo->meta('Referanslar', $intro, '/referanslar'), [$this->seo->organization(), $this->seo->breadcrumbs($crumbs)], 'page_view');
    }

    public function reference(string $slug): Response
    {
        $ref = $this->site->reference($slug) ?? abort(404);
        $crumbs = [['Ana Sayfa', '/'], ['Referanslar', '/referanslar'], [$ref['name'], $ref['url']]];

        return $this->render('site.reference', ['ref' => $ref, 'html' => SiteMarkdown::render($ref['body']), 'crumbs' => $crumbs],
            $this->seo->meta($ref['name'].' — '.$ref['short_description'], $ref['short_description'].'. '.SiteMarkdown::plain($ref['body'], 120), $ref['url'], image: $ref['logo']),
            [$this->seo->organization(), $this->seo->breadcrumbs($crumbs)], 'page_view');
    }

    public function thanks(): Response
    {
        return $this->render('site.thanks', [], $this->seo->meta('Teşekkürler', 'Talebiniz alındı.', '/iletisim/tesekkurler', index: false), [], null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     * @param  list<array<string, mixed>|null>  $schema
     */
    public function render(string $view, array $data, array $meta, array $schema, ?string $event, int $status = 200): Response
    {
        request()->attributes->set('csp_extra', $this->analytics->cspHosts());

        return response()->view($view, $data + [
            'meta' => $meta, 'jsonLd' => $schema === [] ? null : SiteSeo::jsonLd($schema), 'event' => $event,
            'org' => $this->settings->get('organization'), 'analytics' => $this->analytics->config(),
            'serviceCategories' => $this->site->categories('service'), 'formStamp' => SiteInquiries::stamp(),
            'interests' => $this->inquiries->interests(), 'inquiryAvailable' => $this->inquiries->available(),
        ], $status)->header('Cache-Control', $status === 200 && ($meta['robots'] ?? '') !== '' ? 'public, max-age='.$this->settings->cacheSeconds() : 'no-store')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
