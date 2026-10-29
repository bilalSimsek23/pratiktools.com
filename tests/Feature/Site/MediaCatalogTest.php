<?php

namespace Tests\Feature\Site;

use App\Http\Middleware\PanelRecentAuth;
use App\Http\Middleware\PanelTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Site\InitialContent;
use App\Site\MediaCatalog;
use App\Site\SiteContents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Media technologies & production automation catalog (CMS content).
 */
final class MediaCatalogTest extends TestCase
{
    use InteractsWithTenants;

    private const ORIGIN = 'https://pratiktools.test';

    private const SERVICES = ['video-ve-ses-produksiyonu', 'after-effects-script-ve-panel-gelistirme', 'premiere-pro-otomasyonlari', 'render-otomasyonu',
        'render-farm-sistemleri', 'toplu-video-ve-icerik-uretimi', 'ozel-produksiyon-araclari', 'ai-destekli-medya-is-akislari'];

    private const SOLUTIONS = ['after-effects-otomasyonu', 'premiere-pro-otomasyonu', 'render-farm-kurulumu', 'video-produksiyon-otomasyonu'];

    private Tenant $site;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['app.url' => self::ORIGIN]);
        [$this->site, $this->owner] = $this->createTenant('pratiktools');
        $this->artisan('site:setup', ['--tenant' => 'pratiktools'])->assertSuccessful();
    }

    private function as(User $user): self
    {
        return $this->actingAs($user, 'web')->withSession([PanelTenant::SESSION_TENANT => $this->site->id, PanelRecentAuth::SESSION_KEY => time()]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function graph(TestResponse $r): array
    {
        preg_match('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', (string) $r->getContent(), $m);

        return json_decode($m[1] ?? 'null', true, flags: JSON_THROW_ON_ERROR)['@graph'];
    }

    public function test_media_category_services_and_landing_pages_are_public_with_seo(): void
    {
        $category = DB::table('site_categories')->where('slug', MediaCatalog::CATEGORY['slug'])->sole();
        $this->assertSame('Medya Teknolojileri & Prodüksiyon Otomasyonu', $category->name);
        $this->assertSame(8, DB::table('site_contents')->where('type', 'service')->where('category_id', $category->id)->where('status', 'published')->count());

        $titles = [];
        $sitemap = (string) $this->get('/sitemap.xml')->getContent();
        foreach ([...array_map(fn ($s) => "/hizmetler/{$s}", self::SERVICES), ...array_map(fn ($s) => "/cozumler/{$s}", self::SOLUTIONS)] as $url) {
            $r = $this->get($url)->assertOk()->assertSee('<link rel="canonical" href="'.self::ORIGIN.$url.'">', false);
            $html = (string) $r->getContent();
            preg_match('#<title>(.*?)</title>#', $html, $t);
            $titles[] = $t[1];
            $this->assertMatchesRegularExpression('#<meta name="description" content="[^"]{50,}">#', $html, $url);
            $types = array_column($this->graph($r), '@type');
            $this->assertSame(['Organization', 'Service', 'FAQPage', 'BreadcrumbList'], $types, $url);
            $this->assertStringContainsString('class="crumbs', $html);
            $this->assertStringContainsString('İlgili içerikler', $html, "{$url} links to related pages");
            $this->assertStringContainsString(self::ORIGIN.$url.'<', $sitemap);
        }
        $this->assertSame(count($titles), count(array_unique($titles)), 'Unique titles.');

        // The services list groups them under the new category.
        $this->get('/hizmetler')->assertSee('id="'.MediaCatalog::CATEGORY['slug'].'"', false)->assertSee('Render Farm Sistemleri');
    }

    public function test_landing_pages_carry_the_technical_explanations_and_cta_in_server_html(): void
    {
        $this->get('/cozumler/render-farm-kurulumu')->assertSee('Render farm nedir?')->assertSee('dağıtık render', false)->assertSee('hibrit')
            ->assertSee('Render altyapınızı birlikte planlayalım');
        $this->get('/cozumler/after-effects-otomasyonu')->assertSee('After Effects sürecinizi anlatın')->assertSee('Toplu render');
        $this->get('/cozumler/premiere-pro-otomasyonu')->assertSee('Kurgu sürecinizi otomatikleştirelim');
        $this->get('/cozumler/video-produksiyon-otomasyonu')->assertSee('şablon + veri + render kuyruğu', false)->assertSee('render farm');
        $this->get('/hizmetler/ai-destekli-medya-is-akislari')->assertSee('insan kontrolü', false);
    }

    public function test_no_adobe_partnership_impression_and_no_invented_figures(): void
    {
        foreach (['/hizmetler/after-effects-script-ve-panel-gelistirme', '/hizmetler/premiere-pro-otomasyonlari', '/cozumler/after-effects-otomasyonu', '/cozumler/premiere-pro-otomasyonu'] as $url) {
            $html = mb_strtolower(strip_tags((string) $this->get($url)->getContent()));
            $this->assertStringContainsString('adobe inc.\'in ticari markalarıdır', html_entity_decode($html, ENT_QUOTES), $url);
            foreach (['adobe partner', 'yetkili adobe', 'adobe onaylı', 'sertifikalı'] as $claim) {
                $this->assertStringNotContainsString($claim, $html, $url);
            }
        }
        foreach ([...MediaCatalog::services(), ...MediaCatalog::solutions()] as $c) {
            $text = (string) json_encode($c, JSON_UNESCAPED_UNICODE);
            $this->assertDoesNotMatchRegularExpression('/\d+\s*(x|kat|%)\b|%\s*\d+/u', $text, "{$c['slug']} has no performance figures");
        }
    }

    public function test_homepage_message_and_main_cards_come_from_the_cms(): void
    {
        $home = $this->get('/')->assertOk()->assertSee('İş süreçlerini yazılım, otomasyon ve yapay zekâyla', false)->assertSee('Medya Teknolojileri &amp; Prodüksiyon Otomasyonu', false);
        foreach (['E-Ticaret &amp; ERP', 'Pazarlama Otomasyonu', 'Yapay Zeka &amp; İş Akışı', 'Özel Yazılım &amp; API', 'Veri &amp; Raporlama'] as $card) {
            $home->assertSee($card, false);
        }
        // Change the cards in the panel: no code.
        $id = (string) DB::table('site_contents')->where('type', 'page')->where('slug', 'home')->value('id');
        $page = DB::table('site_contents')->where('id', $id)->first();
        $this->as($this->owner)->post("/app/website/content/{$id}", ['title' => $page->title, 'slug' => 'home', 'excerpt' => $page->excerpt,
            'capability_cards' => "Yeni Kart | Yeni açıklama | /hizmetler/teknik-seo\nKötü bağlantı | x | javascript:alert(1)"])->assertSessionHasNoErrors();
        $html = (string) $this->get('/')->getContent();
        $this->assertStringContainsString('<a href="/hizmetler/teknik-seo">Yeni Kart</a>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('Kötü bağlantı', $html);
    }

    public function test_home_upgrade_keeps_panel_edits_and_install_is_idempotent(): void
    {
        $id = (string) DB::table('site_contents')->where('type', 'page')->where('slug', 'home')->value('id');
        $first = 'E-ticaret, otomasyon ve yapay zekayı işletmenizin gerçek sistemlerine bağlıyoruz.';
        $data = json_decode((string) DB::table('site_contents')->where('id', $id)->value('data'), true);
        // Production state: first hero, but the operator rewrote the hero text.
        $data['hero_title'] = $first;
        $data['hero_text'] = 'Operatörün yazdığı metin.';
        unset($data['capability_cards']);
        DB::table('site_contents')->where('id', $id)->update(['data' => json_encode($data, JSON_UNESCAPED_UNICODE)]);

        $result = InitialContent::upgradeHome();
        $this->assertContains('hero_title', $result['updated']);
        $this->assertContains('capability_cards', $result['updated']);
        $this->assertContains('hero_text', $result['kept']);
        $after = json_decode((string) DB::table('site_contents')->where('id', $id)->value('data'), true);
        $this->assertSame([InitialContent::HOME['hero_title'], 'Operatörün yazdığı metin.'], [$after['hero_title'], $after['hero_text']]);

        // Re-running adds nothing; a page the operator deleted is not restored.
        $count = DB::table('site_contents')->count();
        DB::table('site_contents')->where('type', 'service')->where('slug', 'render-farm-sistemleri')->update(['title' => 'Operatör başlığı']);
        $this->assertSame(0, MediaCatalog::install());
        $this->assertSame($count, DB::table('site_contents')->count());
        $this->assertSame('Operatör başlığı', DB::table('site_contents')->where('slug', 'render-farm-sistemleri')->value('title'));
    }

    public function test_media_pages_are_editable_and_unpublishable_in_the_panel(): void
    {
        $id = (string) DB::table('site_contents')->where('type', 'solution')->where('slug', 'render-farm-kurulumu')->value('id');
        $this->as($this->owner)->get("/app/website/content/{$id}")->assertOk()->assertSee('Render Farm Kurulumu');
        $this->as($this->owner)->post("/app/website/content/{$id}/status", ['status' => 'draft'])->assertSessionHasNoErrors();
        $this->get('/cozumler/render-farm-kurulumu')->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee('render-farm-kurulumu');
        $this->as($this->owner)->post("/app/website/content/{$id}/status", ['status' => 'published'])->assertSessionHasNoErrors();
        $this->get('/cozumler/render-farm-kurulumu')->assertOk();

        // A new media service needs only the panel.
        $this->as($this->owner)->post('/app/website/service', ['title' => 'Podcast Otomasyonu'])->assertRedirect();
        $new = (string) DB::table('site_contents')->where('title', 'Podcast Otomasyonu')->value('id');
        $category = (string) DB::table('site_categories')->where('slug', MediaCatalog::CATEGORY['slug'])->value('id');
        $this->as($this->owner)->post("/app/website/content/{$new}", ['title' => 'Podcast Otomasyonu', 'slug' => 'podcast-otomasyonu', 'excerpt' => 'Ses iş akışları.', 'category_id' => $category]);
        $this->as($this->owner)->post("/app/website/content/{$new}/status", ['status' => 'published'])->assertSessionHasNoErrors();
        $this->get('/hizmetler/podcast-otomasyonu')->assertOk()->assertSee('Medya Teknolojileri &amp; Prodüksiyon Otomasyonu', false);
    }

    public function test_references_are_not_linked_automatically_but_can_be_linked_in_the_panel(): void
    {
        $motion = DB::table('site_references')->where('slug', 'motioncurate')->sole();
        $this->assertSame(0, DB::table('site_reference_contents')->where('reference_id', $motion->id)->count(), 'No automatic relation.');
        $this->assertSame('draft', $motion->status);

        $this->as($this->owner)->get("/app/website/references/{$motion->id}")->assertOk()->assertSee('Render Farm Sistemleri')->assertSee('Render Otomasyonu');
        $render = (string) DB::table('site_contents')->where('slug', 'render-otomasyonu')->value('id');
        $this->as($this->owner)->post("/app/website/references/{$motion->id}", ['name' => 'MotionCurate', 'slug' => 'motioncurate', 'short_description' => $motion->short_description,
            'active' => 1, 'contents' => [$render]])->assertSessionHasNoErrors();
        $this->assertSame([$render], DB::table('site_reference_contents')->where('reference_id', $motion->id)->pluck('content_id')->all());
        SiteContents::bump();
        $this->get('/referanslar')->assertDontSee('MotionCurate', false);
    }
}
