<?php

namespace Tests\Feature\Site;

use App\Audit\Actor;
use App\Http\Middleware\PanelRecentAuth;
use App\Http\Middleware\PanelTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Site\ContentType;
use App\Site\SiteContents;
use App\Site\SiteMedia;
use App\Site\SiteSettings;
use App\Tenancy\TenantRole;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Brand (logos, favicon, Apple Touch Icon, share image), global and
 * page-level SEO, Open Graph, structured data and social profiles, all
 * managed from Site Ayarları without a deploy.
 */
final class BrandSeoTest extends TestCase
{
    use InteractsWithTenants;

    private Tenant $site;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['app.url' => 'https://pratiktools.test', 'site.owner_tenant' => null]);
        config(['filesystems.disks.site-media' => ['driver' => 's3', 'bucket' => 'test-bucket', 'region' => 'auto', 'key' => 'k', 'secret' => 's', 'throw' => false]]);
        Storage::fake('site-media');
        [$this->site, $this->owner] = $this->createTenant('pratiktools');
        $this->artisan('site:setup', ['--tenant' => 'pratiktools'])->assertSuccessful();
    }

    private function as(User $user, bool $recentAuth = true): self
    {
        return $this->actingAs($user, 'web')->withSession([PanelTenant::SESSION_TENANT => $this->site->id, PanelRecentAuth::SESSION_KEY => $recentAuth ? time() : 0]);
    }

    private function settings(string $group, array $input, ?User $user = null): TestResponse
    {
        return $this->as($user ?? $this->owner)->post("/app/website/settings/{$group}", $input);
    }

    private function brandImage(string $slot, UploadedFile $file, ?User $user = null): TestResponse
    {
        return $this->as($user ?? $this->owner)->post("/app/website/settings/brand/{$slot}", ['image' => $file, 'alt' => 'Marka']);
    }

    /**
     * Runs as production; the test client sends no CSRF token, which only
     * the testing environment tolerates.
     */
    private function inProduction(\Closure $fn): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->detectEnvironment(fn () => 'production');
        try {
            $fn();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    private function brand(string $slot): string
    {
        return (string) app(SiteSettings::class)->get('brand')[$slot];
    }

    private function service(string $title, array $input = []): string
    {
        $contents = app(SiteContents::class);
        $id = $contents->create(ContentType::Service, $title, Actor::system());
        $contents->update($id, $input + ['excerpt' => "{$title} özeti"], Actor::system());
        $contents->publish($id, Actor::system());

        return $id;
    }

    private function url(string $id): string
    {
        return '/hizmetler/'.DB::table('site_contents')->where('id', $id)->value('slug');
    }

    /**
     * @return array<string, mixed>
     */
    private function graph(TestResponse $r, string $type): array
    {
        preg_match('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', (string) $r->getContent(), $m);

        return collect(json_decode($m[1], true)['@graph'])->firstWhere('@type', $type) ?? [];
    }

    private function meta(TestResponse $r, string $attr, string $name): ?string
    {
        return preg_match('#<meta '.$attr.'="'.preg_quote($name, '#').'" content="([^"]*)">#', (string) $r->getContent(), $m) === 1 ? html_entity_decode($m[1]) : null;
    }

    public function test_global_seo_settings_are_the_fallback(): void
    {
        $this->settings('seo', ['default_title' => 'Yazılım ve otomasyon', 'title_suffix' => '— PT', 'default_description' => 'Genel site açıklaması.',
            'robots_index' => 1, 'robots_follow' => 1])->assertSessionHasNoErrors();
        DB::table('site_contents')->where('type', 'page')->where('slug', 'home')->update(['seo_title' => null, 'seo_description' => null, 'excerpt' => '']);

        $home = $this->get('/')->assertOk();
        $home->assertSee('<title>Yazılım ve otomasyon — PT</title>', false);
        $this->assertSame('Genel site açıklaması.', $this->meta($home, 'name', 'description'));
        $this->assertSame('Yazılım ve otomasyon', $this->meta($home, 'property', 'og:title'));

        // A service without SEO fields: its own title and excerpt, then the suffix.
        $s = $this->get($this->url($this->service('Ticimax Entegrasyonu')))->assertOk();
        $s->assertSee('<title>Ticimax Entegrasyonu — PT</title>', false);
        $this->assertSame('Ticimax Entegrasyonu özeti', $this->meta($s, 'name', 'description'));
        $this->assertSame('Ticimax Entegrasyonu özeti', $this->meta($s, 'property', 'og:description'));
    }

    public function test_page_seo_fields_override_content_and_settings(): void
    {
        $this->settings('seo', ['default_description' => 'Genel', 'robots_index' => 1, 'robots_follow' => 1]);
        $id = $this->service('Nebim Entegrasyonu', ['seo_title' => 'Nebim V3 entegrasyonu', 'seo_description' => 'Nebim için özel açıklama.',
            'og_title' => 'Nebim paylaşım başlığı', 'og_description' => 'Paylaşım açıklaması.']);

        $r = $this->get($this->url($id))->assertOk();
        $r->assertSee('<title>Nebim V3 entegrasyonu | PratikTools</title>', false);
        $this->assertSame('Nebim için özel açıklama.', $this->meta($r, 'name', 'description'));
        $this->assertSame('Nebim paylaşım başlığı', $this->meta($r, 'property', 'og:title'));
        $this->assertSame('Paylaşım açıklaması.', $this->meta($r, 'property', 'og:description'));
        $this->assertSame('Nebim paylaşım başlığı', $this->meta($r, 'name', 'twitter:title'));
        $this->assertSame('website', $this->meta($r, 'property', 'og:type'));
        $this->assertSame('https://pratiktools.test'.$this->url($id), $this->meta($r, 'property', 'og:url'));

        // Long values are saved (the panel only warns).
        $this->as($this->owner)->post("/app/website/content/{$id}", ['title' => 'Nebim Entegrasyonu', 'slug' => 'nebim-entegrasyonu',
            'seo_title' => str_repeat('Uzun başlık ', 10), 'seo_description' => str_repeat('Uzun açıklama ', 20)])->assertSessionHasNoErrors();
        $this->assertSame(trim(str_repeat('Uzun başlık ', 10)), DB::table('site_contents')->where('id', $id)->value('seo_title'));
    }

    public function test_og_image_falls_back_to_the_default_and_a_page_image_overrides_it(): void
    {
        $plain = $this->service('Görselsiz Hizmet');
        $r = $this->get($this->url($plain));
        $this->assertNull($this->meta($r, 'property', 'og:image'));
        $this->assertSame('summary', $this->meta($r, 'name', 'twitter:card'));

        $this->brandImage('og_image', UploadedFile::fake()->image('og.png', 1200, 630))->assertSessionHasNoErrors();
        $default = $this->brand('og_image');
        $r = $this->get($this->url($plain));
        $this->assertSame("https://pratiktools.test/media/{$default}/1200.jpg", $this->meta($r, 'property', 'og:image'));
        $this->assertSame('summary_large_image', $this->meta($r, 'name', 'twitter:card'));
        $this->assertSame("https://pratiktools.test/media/{$default}/1200.jpg", $this->meta($r, 'name', 'twitter:image'));

        $own = app(SiteMedia::class)->upload(UploadedFile::fake()->image('own.png', 1000, 520), 'Hizmet görseli', Actor::system());
        $withImage = $this->service('Görselli Hizmet', ['social_image_id' => $own]);
        $r = $this->get($this->url($withImage));
        $this->assertSame("https://pratiktools.test/media/{$own}/1000.jpg", $this->meta($r, 'property', 'og:image'));
        $this->assertSame('Hizmet görseli', $this->meta($r, 'property', 'og:image:alt'));
    }

    public function test_favicon_and_apple_touch_icon_are_rendered_as_png_with_a_safe_fallback(): void
    {
        $this->get('/')->assertSee('<link rel="icon" href="/favicon.ico">', false)->assertDontSee('apple-touch-icon');

        $this->brandImage('favicon', UploadedFile::fake()->image('fav.png', 512, 512))->assertSessionHasNoErrors();
        $this->brandImage('apple_touch_icon', UploadedFile::fake()->image('apple.jpg', 180, 180))->assertSessionHasNoErrors();
        $fav = $this->brand('favicon');
        $apple = $this->brand('apple_touch_icon');

        $home = $this->get('/')->assertOk();
        $home->assertSee("<link rel=\"icon\" type=\"image/png\" sizes=\"32x32\" href=\"/media/{$fav}/32.png\">", false)
            ->assertSee("<link rel=\"icon\" type=\"image/png\" sizes=\"192x192\" href=\"/media/{$fav}/192.png\">", false)
            ->assertSee("<link rel=\"apple-touch-icon\" sizes=\"180x180\" href=\"/media/{$apple}/180.png\">", false)
            ->assertDontSee('/favicon.ico');

        $png = $this->get("/media/{$fav}/32.png")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame([32, 32], array_slice((array) getimagesizefromstring($png->streamedContent()), 0, 2));
        $this->assertSame('icon', DB::table('site_media')->where('id', $fav)->value('kind'));
        // Icons are not offered as content share images.
        $this->as($this->owner)->get('/app/website/content/'.DB::table('site_contents')->value('id'))->assertOk()->assertDontSee("value=\"{$fav}\"", false);
    }

    public function test_logos_replace_the_text_brand_and_fall_back_safely(): void
    {
        $this->get('/')->assertSee('<span class="brand-mark" aria-hidden="true">P</span>PratikTools', false)->assertDontSee('class="brand-logo"', false);

        $this->brandImage('header_logo_light', UploadedFile::fake()->image('logo.png', 400, 100))->assertSessionHasNoErrors();
        $logo = $this->brand('header_logo_light');
        $home = $this->get('/')->assertOk();
        $this->assertSame(2, substr_count((string) $home->getContent(), "src=\"/media/{$logo}/400.webp\""), 'Header, and footer falling back to the header logo.');
        $home->assertSee('width="144" height="36"', false)->assertDontSee('class="brand-mark"', false);

        // Dark header without a dark-background logo: text brand, never a light logo on a dark background.
        $this->settings('brand', ['site_name' => 'PratikTools', 'short_name' => 'Pratik', 'header_background' => 'dark', 'footer_background' => 'light'])->assertSessionHasNoErrors();
        $home = $this->get('/');
        $home->assertSee('class="site-header is-dark"', false)->assertSee('<span class="brand-mark" aria-hidden="true">P</span>Pratik', false);
        $this->assertSame(1, substr_count((string) $home->getContent(), "/media/{$logo}/400.webp"));

        $this->brandImage('header_logo_dark', UploadedFile::fake()->image('dark.png', 300, 100))->assertSessionHasNoErrors();
        $this->get('/')->assertSee('src="/media/'.$this->brand('header_logo_dark').'/300.webp"', false);
    }

    public function test_canonical_base_url_is_used_everywhere_and_must_be_https_in_production(): void
    {
        $this->settings('seo', ['canonical_base' => 'https://www.pratiktools.example/', 'robots_index' => 1, 'robots_follow' => 1])->assertSessionHasNoErrors();
        $id = $this->service('Canonical Testi');

        $r = $this->get($this->url($id));
        $r->assertSee('<link rel="canonical" href="https://www.pratiktools.example'.$this->url($id).'">', false);
        $this->assertSame('https://www.pratiktools.example'.$this->url($id), $this->meta($r, 'property', 'og:url'));
        $this->get('/sitemap.xml')->assertSee('<loc>https://www.pratiktools.example'.$this->url($id).'</loc>', false)->assertDontSee('pratiktools.test');
        $this->get('/robots.txt')->assertSee('Sitemap: https://www.pratiktools.example/sitemap.xml');
        $this->assertSame('https://www.pratiktools.example/#organization', $this->graph($this->get('/'), 'Organization')['@id']);

        $this->settings('seo', ['canonical_base' => 'https://pratiktools.example/yol'])->assertSessionHasErrors('action');
        $this->settings('seo', ['canonical_base' => 'javascript:alert(1)'])->assertSessionHasErrors('action');
        $this->inProduction(fn () => $this->settings('seo', ['canonical_base' => 'http://pratiktools.example'])->assertSessionHasErrors('action'));
        $this->assertSame('https://www.pratiktools.example', app(SiteSettings::class)->canonicalBase());
    }

    public function test_noindex_per_page_and_for_the_whole_site(): void
    {
        $hidden = $this->service('Gizli Hizmet', ['robots_noindex' => true]);
        $open = $this->service('Açık Hizmet');

        $this->assertSame('noindex, follow', $this->meta($this->get($this->url($hidden)), 'name', 'robots'));
        $this->assertSame('index, follow, max-image-preview:large', $this->meta($this->get($this->url($open)), 'name', 'robots'));
        $this->get('/sitemap.xml')->assertDontSee($this->url($hidden))->assertSee($this->url($open));

        $this->settings('seo', ['robots_index' => 0, 'robots_follow' => 0])->assertSessionHasNoErrors();
        $this->assertSame('noindex, nofollow', $this->meta($this->get($this->url($open)), 'name', 'robots'));
        $this->assertSame('noindex, nofollow', $this->meta($this->get('/'), 'name', 'robots'));
        $this->assertSame(1, $this->inTenant($this->site, fn () => DB::table('audit_logs')->where('action', 'site.settings_changed')->where('resource_id', 'seo')
            ->whereRaw("metadata->>'robots_index' = 'false'")->count()));
    }

    public function test_organization_and_website_schema_come_from_settings_without_invented_data(): void
    {
        $this->settings('seo', ['organization_name' => 'PratikTools Yazılım', 'organization_description' => 'Yazılım ve otomasyon çözümleri.', 'robots_index' => 1, 'robots_follow' => 1]);
        $this->settings('brand', ['site_name' => 'PratikTools', 'short_name' => 'PT']);
        $this->brandImage('header_logo_light', UploadedFile::fake()->image('logo.png', 400, 100));
        $logo = $this->brand('header_logo_light');

        $home = $this->get('/')->assertOk();
        $org = $this->graph($home, 'Organization');
        $this->assertSame('PratikTools Yazılım', $org['name']);
        $this->assertSame('Yazılım ve otomasyon çözümleri.', $org['description']);
        $this->assertSame(['@type' => 'ImageObject', 'url' => "https://pratiktools.test/media/{$logo}/400.jpg"], $org['logo']);
        foreach (['address', 'telephone', 'sameAs', 'email'] as $absent) {
            $this->assertArrayNotHasKey($absent, $org, "No {$absent} unless recorded in Site Ayarları.");
        }
        $site = $this->graph($home, 'WebSite');
        $this->assertSame(['PratikTools', 'PT'], [$site['name'], $site['alternateName']]);

        // Existing structured data keeps working.
        $id = $this->service('Şema Hizmeti', ['faq' => "Soru bir?\nCevap bir."]);
        $r = $this->get($this->url($id));
        $this->assertNotSame([], $this->graph($r, 'Service'));
        $this->assertNotSame([], $this->graph($r, 'FAQPage'));
        $this->assertCount(3, $this->graph($r, 'BreadcrumbList')['itemListElement']);
    }

    public function test_same_as_lists_only_filled_and_valid_profiles(): void
    {
        $this->settings('social', ['linkedin' => 'https://www.linkedin.com/company/pratiktools', 'instagram' => '', 'youtube' => '', 'facebook' => '', 'x' => 'https://x.com/pratiktools'])
            ->assertSessionHasNoErrors();
        $this->settings('organization', ['name' => 'PratikTools', 'same_as' => "https://github.com/pratiktools\nhttps://x.com/pratiktools"]);

        $this->assertSame(['https://www.linkedin.com/company/pratiktools', 'https://x.com/pratiktools', 'https://github.com/pratiktools'],
            $this->graph($this->get('/'), 'Organization')['sameAs']);

        foreach ([['instagram' => 'https://evil.example/instagram.com/x'], ['facebook' => 'http://facebook.com/pt'], ['youtube' => 'https://youtube.com.evil.example/@pt'],
            ['linkedin' => 'https://linkedin.com/in/x"><script>'], ['x' => 'javascript:alert(1)']] as $bad) {
            $this->settings('social', $bad)->assertSessionHasErrors('action');
        }
        $this->assertSame('https://www.linkedin.com/company/pratiktools', app(SiteSettings::class)->get('social')['linkedin'], 'A rejected save changes nothing.');

        $this->settings('social', ['linkedin' => '', 'x' => ''])->assertSessionHasNoErrors();
        $this->assertSame(['https://github.com/pratiktools', 'https://x.com/pratiktools'], $this->graph($this->get('/'), 'Organization')['sameAs']);
    }

    public function test_only_owner_and_admin_with_a_recent_password_can_change_brand_and_seo(): void
    {
        $this->post('/app/website/settings/seo', ['default_title' => 'x'])->assertRedirect(route('panel.login'));
        foreach ([TenantRole::Marketer, TenantRole::Approver, TenantRole::Viewer] as $role) {
            $user = $this->addMember($this->site, $role);
            $this->settings('seo', ['default_title' => 'Ele geçirildi'], $user)->assertForbidden();
            $this->settings('brand', ['site_name' => 'Ele geçirildi'], $user)->assertForbidden();
            $this->settings('social', ['x' => 'https://x.com/evil'], $user)->assertForbidden();
            $this->brandImage('favicon', UploadedFile::fake()->image('f.png', 64, 64), $user)->assertForbidden();
        }
        $this->as($this->owner, recentAuth: false)->post('/app/website/settings/seo', ['default_title' => 'x'])->assertRedirect(route('panel.confirm'));
        $this->as($this->owner, recentAuth: false)->post('/app/website/settings/brand/favicon', ['image' => UploadedFile::fake()->image('f.png', 64, 64)])->assertRedirect(route('panel.confirm'));
        $this->assertSame('', app(SiteSettings::class)->get('seo')['default_title']);
        $this->assertSame([], Storage::disk('site-media')->allFiles());

        $admin = $this->addMember($this->site, TenantRole::Admin);
        $this->settings('seo', ['default_title' => 'Yönetici başlığı', 'robots_index' => 1, 'robots_follow' => 1], $admin)->assertSessionHasNoErrors();
        $this->brandImage('favicon', UploadedFile::fake()->image('f.png', 64, 64), $admin)->assertSessionHasNoErrors();
        $audit = $this->inTenant($this->site, fn () => DB::table('audit_logs')->where('action', 'site.settings_changed')->whereIn('resource_id', ['seo', 'brand'])->count());
        $this->assertSame(2, $audit, 'Every change is audited.');
        $this->as($admin)->get('/app/website/settings')->assertOk()->assertSee('Marka görselleri')->assertSee('data-serp', false);
    }

    public function test_unsafe_or_unsuitable_brand_images_are_rejected_and_nothing_is_stored(): void
    {
        $this->brandImage('favicon', UploadedFile::fake()->createWithContent('f.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))->assertSessionHasErrors('action');
        $this->brandImage('header_logo_light', UploadedFile::fake()->createWithContent('logo.png', "\x89PNG\r\n<?php system('id'); ?>"))->assertSessionHasErrors('action');
        $this->brandImage('favicon', UploadedFile::fake()->image('wide.png', 128, 64))->assertSessionHasErrors('action');
        $this->brandImage('favicon', UploadedFile::fake()->image('tiny.png', 32, 32))->assertSessionHasErrors('action');
        $this->brandImage('apple_touch_icon', UploadedFile::fake()->image('small.png', 120, 120))->assertSessionHasErrors('action');
        $this->brandImage('og_image', UploadedFile::fake()->image('small.png', 150, 80))->assertSessionHasErrors('action');
        $this->as($this->owner)->post('/app/website/settings/brand/unknown_slot', ['image' => UploadedFile::fake()->image('x.png', 64, 64)])->assertNotFound();
        // Raw HTML in text settings is stored as plain text and escaped.
        $this->settings('brand', ['site_name' => '<script>alert(1)</script>PT', 'short_name' => ''])->assertSessionHasNoErrors();
        $this->get('/')->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);

        $this->assertSame(0, DB::table('site_media')->count());
        $this->assertSame([], Storage::disk('site-media')->allFiles());
        foreach (['favicon', 'header_logo_light', 'apple_touch_icon', 'og_image'] as $slot) {
            $this->assertSame('', $this->brand($slot));
        }
    }

    public function test_production_without_object_storage_refuses_brand_uploads_and_keeps_the_fallbacks(): void
    {
        $this->as($this->owner)->post('/app/website/settings/media', ['disk' => 'local'])->assertSessionHasNoErrors();
        Storage::fake('local');
        $this->inProduction(function () {
            $this->brandImage('favicon', UploadedFile::fake()->image('f.png', 64, 64))->assertSessionHasErrors('action');
            $this->brandImage('header_logo_light', UploadedFile::fake()->image('l.png', 400, 100))->assertSessionHasErrors('action');
            $this->as($this->owner)->get('/app/website/settings')->assertOk()->assertSee('Yükleme kapalı');
        });
        $this->assertSame(0, DB::table('site_media')->count());
        Storage::disk('local')->assertDirectoryEmpty('/');
        $this->get('/')->assertSee('<link rel="icon" href="/favicon.ico">', false)->assertSee('class="brand-mark"', false);
    }

    public function test_changes_invalidate_cached_pages_and_replaced_images_get_new_urls(): void
    {
        DB::table('site_contents')->where('type', 'page')->where('slug', 'home')->update(['seo_title' => null]);
        SiteContents::bump();
        $this->get('/')->assertSee('<title>PratikTools</title>', false);
        $this->settings('brand', ['site_name' => 'Pratik Yazılım', 'short_name' => ''])->assertSessionHasNoErrors();
        $this->get('/')->assertSee('<title>Pratik Yazılım</title>', false)->assertSee('© '.date('Y').' Pratik Yazılım', false);
        $this->settings('seo', ['default_title' => 'Yeni başlık', 'robots_index' => 1, 'robots_follow' => 1]);
        $this->get('/')->assertSee('<title>Yeni başlık | Pratik Yazılım</title>', false);

        $this->brandImage('favicon', UploadedFile::fake()->image('a.png', 64, 64))->assertSessionHasNoErrors();
        $first = $this->brand('favicon');
        $this->get('/')->assertSee("/media/{$first}/32.png", false);
        $this->brandImage('favicon', UploadedFile::fake()->image('b.png', 96, 96))->assertSessionHasNoErrors();
        $second = $this->brand('favicon');

        $this->assertNotSame($first, $second);
        $this->get('/')->assertSee("/media/{$second}/32.png", false)->assertDontSee("/media/{$first}/", false);
        $this->assertNull(DB::table('site_media')->where('id', $first)->first(), 'The replaced icon is removed (no orphans).');
        $this->assertSame([], Storage::disk('site-media')->allFiles("site-media/{$first}"));
        $this->get("/media/{$first}/32.png")->assertNotFound();
        $this->assertSame(1, SiteMedia::usage($second));
        $this->as($this->owner)->post("/app/website/media/{$second}/delete")->assertSessionHasErrors('action');

        $this->as($this->owner)->post('/app/website/settings/brand/favicon', ['remove' => 1])->assertSessionHasNoErrors();
        $this->get('/')->assertSee('<link rel="icon" href="/favicon.ico">', false);
        $this->assertSame(0, DB::table('site_media')->count());
    }
}
