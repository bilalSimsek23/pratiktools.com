<?php

namespace Tests\Feature\Site;

use App\Audit\Actor;
use App\Http\Middleware\PanelRecentAuth;
use App\Http\Middleware\PanelTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Site\Attribution;
use App\Site\ContentStatus;
use App\Site\ContentType;
use App\Site\SiteContents;
use App\Site\SiteError;
use App\Site\SiteInquiries;
use App\Site\SiteMarkdown;
use App\Tenancy\TenantRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * F7.5: public website, CMS, inquiries, SEO and crawler policy.
 */
final class PublicSiteTest extends TestCase
{
    use InteractsWithTenants;

    private const ORIGIN = 'https://pratiktools.test';

    private Tenant $site;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['app.url' => self::ORIGIN, 'site.owner_tenant' => 'pratiktools']);
        [$this->site, $this->owner] = $this->createTenant('pratiktools');
        $this->artisan('site:setup', ['--tenant' => 'pratiktools'])->assertSuccessful();
    }

    private function as(User $user, ?Tenant $tenant = null): self
    {
        return $this->actingAs($user, 'web')->withSession([PanelTenant::SESSION_TENANT => ($tenant ?? $this->site)->id, PanelRecentAuth::SESSION_KEY => time()]);
    }

    private function content(ContentType $type, string $title, array $input = [], bool $publish = true): string
    {
        $contents = app(SiteContents::class);
        $id = $contents->create($type, $title, Actor::system());
        $contents->update($id, $input + ['excerpt' => "{$title} özeti"], Actor::system());
        if ($publish) {
            $contents->publish($id, Actor::system());
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function inquire(array $fields = [], array $headers = [], bool $json = true): TestResponse
    {
        $body = $fields + ['name' => 'Ayşe Yılmaz', 'email' => 'ayse@example.com', 'message' => 'Ticimax ile Nebim entegrasyonu istiyoruz.', 'kind' => 'teklif',
            'ft' => SiteInquiries::stamp(time() - 30), 'website' => ''];
        $server = $headers + ['HTTP_ORIGIN' => self::ORIGIN, 'REMOTE_ADDR' => '198.51.100.'.random_int(1, 250)];
        if ($json) {
            $server['HTTP_ACCEPT'] = 'application/json';
        }

        return $this->call('POST', '/iletisim/talep', $body, [], [], $server);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function jsonLd(TestResponse $r): array
    {
        preg_match('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', (string) $r->getContent(), $m);

        return json_decode($m[1] ?? 'null', true, flags: JSON_THROW_ON_ERROR)['@graph'];
    }

    public function test_homepage_and_initial_pages_are_public_server_rendered_and_cookie_free(): void
    {
        $home = $this->get('/');
        $home->assertOk()->assertSee('E-ticaret, otomasyon ve yapay zekayı', false)->assertSee('Neler yapıyoruz')->assertSee('Ticimax Entegrasyonları')
            ->assertSee('Nasıl çalışıyoruz')->assertSee('Projenizi anlatın')->assertHeader('Cache-Control', 'max-age=300, public');
        $this->assertSame([], $home->headers->getCookies(), 'Public pages set no cookies.');
        $this->assertFalse($home->headers->has('X-Robots-Tag'));
        $this->assertStringNotContainsString('noindex', (string) $home->getContent());

        foreach (['/hizmetler', '/hizmetler/ticimax-entegrasyonlari', '/hizmetler/nebim-v3-entegrasyonlari', '/hizmetler/pazarlama-otomasyonu',
            '/hizmetler/yapay-zeka-ve-is-sureci-otomasyonu', '/hizmetler/api-ozel-entegrasyon', '/hizmetler/teknik-seo', '/cozumler', '/cozumler/ticimax-nebim-entegrasyonu',
            '/cozumler/e-ticaret-otomasyonu', '/cozumler/yapay-zeka-otomasyonu', '/cozumler/email-marketing-otomasyonu', '/projeler', '/blog', '/hakkimizda', '/iletisim'] as $url) {
            $this->get($url)->assertOk()->assertSee('<link rel="canonical" href="'.self::ORIGIN.$url.'">', false);
        }
        // Case study candidates are drafts: not public.
        $this->get('/projeler/ticimax-nebim-v3-entegrasyonu')->assertNotFound();
        $this->get('/projeler')->assertDontSee('Ticimax + Nebim V3 entegrasyonu');
    }

    public function test_the_panel_and_its_auth_are_unchanged_under_app(): void
    {
        $this->get('/app')->assertRedirect(route('panel.login'));
        $this->get('/app/login')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->getJson('/api/v1/tenant')->assertUnauthorized();
    }

    public function test_only_published_content_is_public(): void
    {
        $draft = $this->content(ContentType::Service, 'Gizli Taslak Hizmet', publish: false);
        $this->get('/hizmetler/gizli-taslak-hizmet')->assertNotFound();
        $this->get('/hizmetler')->assertDontSee('Gizli Taslak Hizmet');
        $this->get('/sitemap.xml')->assertDontSee('gizli-taslak-hizmet');

        app(SiteContents::class)->update($draft, ['excerpt' => 'Kısa özet'], Actor::system());
        app(SiteContents::class)->publish($draft, Actor::system());
        $this->get('/hizmetler/gizli-taslak-hizmet')->assertOk()->assertSee('Gizli Taslak Hizmet');
        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=utf-8')->assertSee(self::ORIGIN.'/hizmetler/gizli-taslak-hizmet');

        app(SiteContents::class)->setStatus($draft, ContentStatus::Archived, Actor::system());
        $this->get('/hizmetler/gizli-taslak-hizmet')->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee('gizli-taslak-hizmet');
    }

    public function test_slugs_are_unique_per_type_and_fixed_once_published(): void
    {
        $a = $this->content(ContentType::Service, 'Veri Raporlama', publish: false);
        $b = $this->content(ContentType::Service, 'Veri Raporlama', publish: false);
        $this->assertSame(['veri-raporlama', 'veri-raporlama-2'], DB::table('site_contents')->whereIn('id', [$a, $b])->orderBy('slug')->pluck('slug')->all());
        $this->content(ContentType::Article, 'Veri Raporlama', publish: false);
        $this->assertSame('veri-raporlama', DB::table('site_contents')->where('type', 'article')->where('title', 'Veri Raporlama')->value('slug'), 'Unique per type.');

        try {
            app(SiteContents::class)->update($b, ['slug' => 'veri-raporlama'], Actor::system());
            $this->fail('Duplicate slug accepted.');
        } catch (SiteError) {
        }
        app(SiteContents::class)->publish($a, Actor::system());
        $this->expectException(SiteError::class);
        app(SiteContents::class)->update($a, ['slug' => 'yeni-adres'], Actor::system());
    }

    public function test_seo_meta_and_structured_data_match_the_page(): void
    {
        $r = $this->get('/hizmetler/ticimax-entegrasyonlari');
        $html = (string) $r->getContent();
        $this->assertStringContainsString('<title>Ticimax Entegrasyonu — ERP, Pazaryeri ve Otomasyon | PratikTools</title>', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow, max-image-preview:large">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="'.self::ORIGIN.'/hizmetler/ticimax-entegrasyonlari">', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary">', $html);

        $graph = collect($this->jsonLd($r))->keyBy('@type');
        $this->assertSame(['Organization', 'Service', 'FAQPage', 'BreadcrumbList'], $graph->keys()->all());
        $this->assertSame('Ticimax Entegrasyonları', $graph['Service']['name']);
        $this->assertSame(self::ORIGIN.'/hizmetler', $graph['BreadcrumbList']['itemListElement'][1]['item']);
        foreach ($graph['FAQPage']['mainEntity'] as $q) {
            $this->assertStringContainsString(e($q['name']), $html, 'Every FAQ answer in schema is visible on the page.');
        }

        // No FAQ on the page: no FAQPage.
        $this->content(ContentType::Service, 'SSS Olmayan Hizmet');
        $types = array_column($this->jsonLd($this->get('/hizmetler/sss-olmayan-hizmet')), '@type');
        $this->assertNotContains('FAQPage', $types);
        $this->assertSame(['Organization', 'WebSite'], array_column($this->jsonLd($this->get('/')), '@type'));
        $this->assertContains('BlogPosting', array_column($this->jsonLd($this->get('/blog/'.DB::table('site_contents')->where('id', $this->content(ContentType::Article, 'İlk Yazı'))->value('slug'))), '@type'));

        // A filtered blog list is not a separate indexable page.
        $this->get('/blog?kategori=ticimax')->assertOk()->assertSee('noindex, follow', false)->assertSee('<link rel="canonical" href="'.self::ORIGIN.'/blog">', false);
    }

    public function test_cms_content_cannot_inject_script_or_html(): void
    {
        $this->content(ContentType::Service, 'XSS <script>alert(1)</script> Deneme', [
            'body' => "Merhaba <script>alert('x')</script>\n\n<img src=x onerror=alert(1)>\n\n[tıkla](javascript:alert(1)) ![d](https://evil.example/x.png) [dış](https://example.org)",
            'hero_text' => '<b onmouseover=alert(1)>kalın</b>', 'faq' => "Soru </script><script>alert(1)</script>?\nCevap",
        ]);
        $slug = (string) DB::table('site_contents')->where('title', 'like', 'XSS%')->value('slug');
        $r = $this->get('/hizmetler/'.$slug)->assertOk();
        $html = (string) $r->getContent();
        $this->assertStringNotContainsString("<script>alert('x')</script>", $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('onerror=', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString('<b onmouseover', $html);
        $this->assertStringContainsString('rel="noopener nofollow" target="_blank"', $html);
        $this->assertIsArray($this->jsonLd($r), 'JSON-LD stays valid and closed.');
        $this->assertSame(1, substr_count($html, '<script type="application/ld+json"'));
        $this->assertStringNotContainsString('<script', SiteMarkdown::render('<script>x</script>'));
    }

    public function test_inquiry_is_stored_as_a_lead_of_the_site_owner_with_attribution(): void
    {
        $first = json_encode(['landing' => '/cozumler/ticimax-nebim-entegrasyonu?gclid=SECRETCLICK&email=x@y.z', 'source' => 'google', 'medium' => 'cpc', 'campaign' => 'ticimax-q4', 'gclid' => 1, 'at' => '2026-10-01']);
        $last = json_encode(['landing' => '/iletisim', 'referrer' => 'https://chatgpt.com/c/abc?q=private', 'at' => '2026-10-29']);
        $this->inquire(['first_touch' => $first, 'last_touch' => $last, 'phone' => '+90 555 111 22 33', 'company' => 'Örnek A.Ş.', 'interest' => 'service:ticimax-entegrasyonlari',
            'sid' => '0d0b5d4e-8a4b-4c55-9a51-0d6a4d0a1111'])
            ->assertOk()->assertJson(['status' => 'accepted']);

        $i = $this->inTenant($this->site, fn () => DB::table('lead_inquiries')->sole());
        $this->assertSame(['teklif', 'Ayşe Yılmaz', 'Örnek A.Ş.', 'Ticimax Entegrasyonları', 'new'], [$i->kind, $i->name, $i->company, $i->interest, $i->status]);
        $a = json_decode((string) $i->attribution, true);
        $this->assertSame(['google_ads', 'ai_search'], [$a['first']['class'], $a['last']['class']]);
        $this->assertSame(['/cozumler/ticimax-nebim-entegrasyonu', 'ticimax-q4', true, 'chatgpt.com'], [$a['first']['landing'], $a['first']['campaign'], $a['first']['has_gclid'], $a['last']['referrer_host']]);
        $stored = (string) json_encode($a);
        foreach (['SECRETCLICK', 'x@y.z', 'q=private', '/c/abc'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
        // Reused F6 lead capture: lead submission + consent recipient, no marketing consent unless ticked.
        $this->assertSame(1, $this->inTenant($this->site, fn () => DB::table('lead_submissions')->count()));
        $this->assertSame(0, $this->inTenant($this->site, fn () => DB::table('consents')->count()));
        $this->assertSame('ayse@example.com', $this->inTenant($this->site, fn () => DB::table('consent_recipients')->where('id', $i->recipient_id)->value('address')));
        $this->assertSame(1, (int) DB::table('site_events_daily')->where('event', 'lead_submit')->sum('count'));

        // The same submission id is a duplicate: same answer, nothing new.
        $this->inquire(['sid' => '0d0b5d4e-8a4b-4c55-9a51-0d6a4d0a1111'])->assertOk()->assertJson(['status' => 'accepted']);
        $this->assertSame(1, $this->inTenant($this->site, fn () => DB::table('lead_inquiries')->count()));

        // Without JavaScript: redirect to the thank-you page.
        $this->inquire(['email' => 'mehmet@example.com'], json: false)->assertStatus(303)->assertRedirect('/iletisim/tesekkurler');
        $this->get('/iletisim/tesekkurler')->assertOk()->assertSee('noindex', false);
    }

    public function test_inquiry_endpoint_is_protected(): void
    {
        $count = fn () => $this->inTenant($this->site, fn () => DB::table('lead_inquiries')->count());

        // Cross-site post.
        $this->inquire([], ['HTTP_ORIGIN' => 'https://evil.example'])->assertForbidden();
        $this->inquire([], ['HTTP_ORIGIN' => 'null'])->assertForbidden();
        // Honeypot and a too-fast / forged render stamp: same success answer, nothing stored.
        $this->inquire(['website' => 'http://spam'])->assertOk()->assertJson(['status' => 'accepted']);
        $this->inquire(['ft' => SiteInquiries::stamp()])->assertOk();
        $this->inquire(['ft' => time().'.'.str_repeat('a', 32)])->assertOk();
        $this->assertSame(0, $count());
        // Validation and limits.
        $this->inquire(['email' => 'not-an-email', 'message' => 'kısa'])->assertStatus(422)->assertJsonStructure(['errors' => ['email', 'message']]);
        $this->inquire(['message' => str_repeat('a', 4001)])->assertStatus(422);
        $this->call('POST', '/iletisim/talep', [], [], [], ['HTTP_ORIGIN' => self::ORIGIN, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            http_build_query(['message' => str_repeat('a', 17000)]))->assertStatus(422);
        $this->assertSame(0, $count());
        // Enumeration: an existing customer address gets the same answer.
        $this->createCustomer($this->site, 'musteri@example.com');
        $this->inquire(['email' => 'musteri@example.com'])->assertOk()->assertJson(['status' => 'accepted']);

        // Per-IP rate limit.
        config(['site.inquiry.per_ip_per_minute' => 2]);
        $ip = ['REMOTE_ADDR' => '203.0.113.77'];
        $this->inquire(['email' => 'a1@example.com'], $ip)->assertOk();
        $this->inquire(['email' => 'a2@example.com'], $ip)->assertOk();
        $this->inquire(['email' => 'a3@example.com'], $ip)->assertStatus(429);

        // Not set up: refused, nothing stored.
        config(['site.owner_tenant' => null]);
        $this->inquire(['email' => 'b@example.com'])->assertStatus(503);
    }

    public function test_public_site_and_inquiries_never_cross_tenants(): void
    {
        [$other, $otherOwner] = $this->createTenant('musteri');
        $this->createCustomer($other, 'gizli@example.com');
        $this->inquire()->assertOk();

        $this->assertSame(0, $this->inTenant($other, fn () => DB::table('lead_inquiries')->count()));
        $this->assertSame(0, DB::table('lead_inquiries')->count(), 'No tenant context: nothing visible (FORCE RLS).');
        foreach (['/', '/hizmetler', '/iletisim', '/sitemap.xml'] as $url) {
            $this->get($url)->assertDontSee('gizli@example.com')->assertDontSee('ayse@example.com');
        }
        // Website management does not exist for another tenant.
        $this->as($otherOwner, $other)->get('/app/website/service')->assertNotFound();
        $this->as($otherOwner, $other)->get('/app')->assertDontSee('Web Sitesi');
    }

    public function test_panel_rbac_edit_and_publish_are_separate(): void
    {
        $marketer = $this->addMember($this->site, TenantRole::Marketer);
        $approver = $this->addMember($this->site, TenantRole::Approver);
        $viewer = $this->addMember($this->site, TenantRole::Viewer);

        $this->as($this->owner)->get('/app')->assertSee('Web Sitesi');
        foreach (['/app/website/page', '/app/website/service', '/app/website/project', '/app/website/article', '/app/website/seo', '/app/website/settings', '/app/website/media', '/app/website/leads'] as $page) {
            $this->as($this->owner)->get($page)->assertOk();
        }
        $this->as($this->owner)->get('/app/website/settings')->assertSee('Aktif')->assertSee('İzin ver');
        $home = (string) DB::table('site_contents')->where('type', 'page')->where('slug', 'home')->value('id');
        $this->as($this->owner)->get("/app/website/content/{$home}")->assertOk()->assertSee('Nasıl çalışıyoruz');
        $this->as($viewer)->get('/app/website/service')->assertForbidden();
        $this->as($marketer)->post('/app/website/service', ['title' => 'Yeni Hizmet'])->assertRedirect();
        $id = (string) DB::table('site_contents')->where('title', 'Yeni Hizmet')->value('id');
        $this->as($marketer)->post("/app/website/content/{$id}", ['title' => 'Yeni Hizmet', 'slug' => 'yeni-hizmet', 'excerpt' => 'Özet'])->assertRedirect()->assertSessionHasNoErrors();
        $this->as($marketer)->post("/app/website/content/{$id}/status", ['status' => 'published'])->assertForbidden();
        $this->as($approver)->post("/app/website/content/{$id}", ['title' => 'Değişti', 'slug' => 'yeni-hizmet'])->assertForbidden();
        $this->as($approver)->post("/app/website/content/{$id}/status", ['status' => 'published'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('published', DB::table('site_contents')->where('id', $id)->value('status'));
        $this->assertSame(1, $this->inTenant($this->site, fn () => DB::table('audit_logs')->where('action', 'site.content_published')->count()));

        $this->as($marketer)->get("/app/website/content/{$id}")->assertOk()->assertDontSee('>Yayınla<', false);
        $this->as($marketer)->get('/app/website/leads')->assertOk();
        $this->as($approver)->get('/app/website/leads')->assertForbidden();
        $this->as($marketer)->post('/app/website/settings/crawlers', ['gptbot' => 'allow'])->assertForbidden();
        $this->as($this->owner)->post('/app/website/settings/analytics', ['ga4_id' => '<script>'])->assertSessionHasErrors('action');

        // Draft preview in the panel, never indexed.
        $draft = $this->content(ContentType::Solution, 'Taslak Çözüm', publish: false);
        $this->as($marketer)->get("/app/website/content/{$draft}/preview")->assertOk()->assertSee('Taslak Çözüm')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_projects_stay_anonymous_without_a_recorded_client_approval(): void
    {
        $id = $this->content(ContentType::Project, 'Stok Senkronizasyonu', ['client_display' => 'Türkiye\'de büyük erkek giyim markası', 'client_name' => 'Gerçek Marka A.Ş.'], publish: false);
        app(SiteContents::class)->publish($id, Actor::system());
        $this->get('/projeler/stok-senkronizasyonu')->assertOk()->assertSee('Türkiye&#039;de büyük erkek giyim markası', false)->assertDontSee('Gerçek Marka');

        app(SiteContents::class)->update($id, ['client_named' => '1'], Actor::system());
        app(SiteContents::class)->setStatus($id, ContentStatus::Draft, Actor::system());
        try {
            app(SiteContents::class)->publish($id, Actor::system());
            $this->fail('Named client published without approval.');
        } catch (SiteError) {
        }
        $this->inTenant($this->site, fn () => app(SiteContents::class)->approveClient($id, 'Müşteriden yazılı onay', Actor::user($this->owner->id)));
        app(SiteContents::class)->publish($id, Actor::system());
        $this->get('/projeler/stok-senkronizasyonu')->assertSee('Gerçek Marka A.Ş.');
    }

    public function test_uploads_are_validated_and_reencoded(): void
    {
        Storage::fake('local');
        $upload = fn (UploadedFile $f) => $this->as($this->owner)->post('/app/website/media', ['image' => $f, 'alt' => 'Görsel']);

        $upload(UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))->assertSessionHasErrors('action');
        $upload(UploadedFile::fake()->createWithContent('shell.jpg', '<?php system($_GET["c"]); ?>'))->assertSessionHasErrors('action');
        $upload(UploadedFile::fake()->create('big.jpg', 6000, 'image/jpeg'))->assertSessionHasErrors('image');
        $this->assertSame(0, DB::table('site_media')->count());

        $png = UploadedFile::fake()->image('ok.png', 1000, 500);
        $upload($png)->assertSessionHasNoErrors();
        $m = DB::table('site_media')->sole();
        $variants = json_decode((string) $m->variants, true);
        $this->assertSame([800, 1000], array_keys($variants['webp']));
        $this->get("/media/{$m->id}/800.webp")->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        $this->get("/media/{$m->id}/800.php")->assertNotFound();
    }

    public function test_robots_policy_for_search_and_ai_crawlers(): void
    {
        $robots = (string) $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8')->getContent();
        $groups = collect(preg_split('/\n\n/', trim($robots)))->mapWithKeys(fn ($g) => [trim((string) strtok($g, "\n")) => $g]);

        foreach (['User-agent: *', 'User-agent: OAI-SearchBot'] as $agent) {
            $this->assertStringContainsString("Allow: /\n", $groups[$agent]);
            $this->assertStringContainsString('Disallow: /app', $groups[$agent]);
            $this->assertStringContainsString('Disallow: /api', $groups[$agent]);
            $this->assertStringNotContainsString("Disallow: /\n", $groups[$agent].'\n');
        }
        $this->assertStringContainsString('Disallow: /', $groups['User-agent: GPTBot'], 'Training crawler disallowed by default.');
        $this->assertStringContainsString('Sitemap: '.self::ORIGIN.'/sitemap.xml', $robots);

        $this->as($this->owner)->post('/app/website/settings/crawlers', ['gptbot' => 'allow'])->assertSessionHasNoErrors();
        $this->assertStringContainsString("User-agent: GPTBot\nAllow: /", (string) $this->get('/robots.txt')->getContent());
        $this->assertFalse(file_exists(public_path('robots.txt')), 'No static robots.txt shadows the managed one.');
        // Crawlers get the same server-rendered content, no auth/CAPTCHA.
        $this->get('/hizmetler/teknik-seo', ['User-Agent' => 'OAI-SearchBot/1.0'])->assertOk()->assertSee('Teknik SEO');
    }

    public function test_events_beacon_accepts_only_known_events_and_public_paths(): void
    {
        $beacon = fn (array $b) => $this->call('POST', '/t', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($b));
        $beacon(['e' => 'service_view', 'p' => '/hizmetler/teknik-seo'])->assertNoContent();
        $beacon(['e' => 'service_view', 'p' => '/app/website'])->assertNoContent();
        $beacon(['e' => 'evil', 'p' => '/'])->assertNoContent();
        $beacon(['e' => 'lead_submit', 'p' => '/'])->assertNoContent();
        $this->assertSame([['service_view', '/hizmetler/teknik-seo', 1]],
            DB::table('site_events_daily')->get()->map(fn ($r) => [$r->event, $r->path, (int) $r->count])->all());
    }

    public function test_attribution_classification(): void
    {
        $class = fn (array $t) => Attribution::touch($t)['class'];
        $this->assertSame('google_ads', $class(['gclid' => 'x']));
        $this->assertSame('meta_ads', $class(['source' => 'facebook', 'medium' => 'paid_social']));
        $this->assertSame('ai_search', $class(['source' => 'chatgpt.com']));
        $this->assertSame('ai_search', $class(['referrer' => 'https://www.perplexity.ai/search']));
        $this->assertSame('campaign', $class(['source' => 'newsletter']));
        $this->assertSame('organic', $class(['referrer' => 'https://www.google.com.tr/']));
        $this->assertSame('referral', $class(['referrer' => 'https://blog.example.org/x']));
        $this->assertSame('direct', $class(['referrer' => self::ORIGIN.'/hizmetler']));
        $this->assertArrayNotHasKey('source', Attribution::touch(['source' => '<script>']));
    }

    public function test_analytics_load_only_from_validated_ids_and_vetted_hosts(): void
    {
        $page = $this->get('/');
        $this->assertStringNotContainsString('googletagmanager', (string) $page->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('id="pt-consent"', (string) $page->getContent());

        $this->as($this->owner)->post('/app/website/settings/analytics', ['ga4_id' => 'G-ABC1234567', 'meta_pixel_id' => '123456789012'])->assertSessionHasNoErrors();
        $page = $this->get('/');
        $csp = (string) $page->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('script-src \'self\' \'nonce-', $csp);
        $this->assertStringContainsString('https://www.googletagmanager.com', $csp);
        $this->assertStringContainsString('https://connect.facebook.net', $csp);
        $this->assertStringContainsString('id="pt-consent"', (string) $page->getContent());
        $this->assertStringNotContainsString('gtag(', (string) $page->getContent(), 'No inline provider script; site.js loads it after consent.');
    }
}
