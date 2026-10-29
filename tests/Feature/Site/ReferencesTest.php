<?php

namespace Tests\Feature\Site;

use App\Audit\Actor;
use App\Http\Middleware\PanelRecentAuth;
use App\Http\Middleware\PanelTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Site\ContentType;
use App\Site\SiteContents;
use App\Tenancy\TenantRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Website references managed entirely in the panel.
 */
final class ReferencesTest extends TestCase
{
    use InteractsWithTenants;

    private const ORIGIN = 'https://pratiktools.test';

    private Tenant $site;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['app.url' => self::ORIGIN, 'site.owner_tenant' => null]);
        [$this->site, $this->owner] = $this->createTenant('pratiktools');
        $this->artisan('site:setup', ['--tenant' => 'pratiktools'])->assertSuccessful();
    }

    private function as(User $user): self
    {
        return $this->actingAs($user, 'web')->withSession([PanelTenant::SESSION_TENANT => $this->site->id, PanelRecentAuth::SESSION_KEY => time()]);
    }

    private function ref(string $slug): object
    {
        return DB::table('site_references')->where('slug', $slug)->sole();
    }

    private function approveName(string $id, ?User $by = null): void
    {
        $this->as($by ?? $this->owner)->post("/app/website/references/{$id}/approval", ['what' => 'name', 'approved' => 1, 'note' => 'Yazılı e-posta onayı', 'confirm' => 1])
            ->assertSessionHasNoErrors();
    }

    private function publish(string $id): void
    {
        $this->as($this->owner)->post("/app/website/references/{$id}/status", ['status' => 'published'])->assertSessionHasNoErrors();
    }

    private function audits(string $action): int
    {
        return $this->inTenant($this->site, fn () => DB::table('audit_logs')->where('action', $action)->count());
    }

    public function test_starting_references_are_drafts_without_approvals_and_not_public(): void
    {
        $rows = DB::table('site_references')->orderBy('featured_order')->get();
        $this->assertSame(['IGS', 'Dost TV', 'DKM', 'N-Extent', 'MotionCurate', 'PazarRadar'], $rows->pluck('name')->all());
        foreach ($rows as $r) {
            $this->assertSame(['draft', false, false, true], [$r->status, $r->client_name_publication_approved, $r->logo_use_approved, $r->homepage_featured]);
        }
        $this->assertSame(['https://n-extent.com', 'https://motioncurate.com', 'https://pazarradar.com'], $rows->pluck('website_url')->filter()->values()->all());
        $this->assertSame(['E-Ticaret Otomasyonu', 'Nebim V3 Entegrasyonu', 'Ticimax Entegrasyonları', 'Özel Ticimax Geliştirmeleri', 'Veri Entegrasyonu', 'Pazarlama Otomasyonu'],
            json_decode((string) $rows[0]->capabilities, true));

        $this->get('/')->assertOk()->assertDontSee('Birlikte çalıştığımız markalar')->assertDontSee('Dost TV');
        $this->get('/referanslar')->assertOk()->assertSee('Referans listesi hazırlanıyor')->assertDontSee('PazarRadar');
        $this->get('/referanslar/igs')->assertNotFound();
        $this->get('/sitemap.xml')->assertSee(self::ORIGIN.'/referanslar</loc>', false)->assertDontSee('/referanslar/igs');
    }

    public function test_publishing_needs_the_client_name_approval_and_public_follows_status(): void
    {
        $igs = $this->ref('igs')->id;
        $this->as($this->owner)->post("/app/website/references/{$igs}/status", ['status' => 'published'])->assertSessionHasErrors('action');
        $this->assertSame('draft', $this->ref('igs')->status);

        $this->as($this->owner)->post("/app/website/references/{$igs}/approval", ['what' => 'name', 'approved' => 1, 'note' => 'x'])->assertSessionHasErrors('action');
        $this->approveName($igs);
        $this->publish($igs);
        $this->get('/referanslar')->assertOk()->assertSee('IGS')->assertSee('E-Ticaret, ERP ve Pazarlama Otomasyonu')->assertSee('Nebim V3 Entegrasyonu');
        $this->get('/referanslar/igs')->assertOk()->assertSee('özel Ticimax geliştirmeleri', false)
            ->assertSee('<link rel="canonical" href="'.self::ORIGIN.'/referanslar/igs">', false);
        $this->get('/sitemap.xml')->assertSee(self::ORIGIN.'/referanslar/igs');
        $this->get('/')->assertSee('Birlikte çalıştığımız markalar')->assertSee('Seçili çalışmalar');

        // Inactive, archived or approval withdrawn: gone at once, no deploy.
        $this->as($this->owner)->post("/app/website/references/{$igs}", ['name' => 'IGS', 'slug' => 'igs', 'short_description' => 'E-Ticaret, ERP ve Pazarlama Otomasyonu'])->assertSessionHasNoErrors();
        $this->get('/referanslar')->assertDontSee('E-Ticaret, ERP ve Pazarlama Otomasyonu');
        $this->as($this->owner)->post("/app/website/references/{$igs}", ['name' => 'IGS', 'slug' => 'igs', 'short_description' => 'E-Ticaret, ERP ve Pazarlama Otomasyonu', 'active' => 1]);
        $this->as($this->owner)->post("/app/website/references/{$igs}/approval", ['what' => 'name', 'approved' => 0])->assertSessionHasNoErrors();
        $this->get('/referanslar')->assertDontSee('E-Ticaret, ERP ve Pazarlama Otomasyonu');
        $this->approveName($igs);
        $this->as($this->owner)->post("/app/website/references/{$igs}/status", ['status' => 'archived'])->assertSessionHasNoErrors();
        $this->get('/referanslar')->assertDontSee('E-Ticaret, ERP ve Pazarlama Otomasyonu');
        $this->get('/referanslar/igs')->assertNotFound();

        $this->assertSame(1, $this->audits('site.reference_published'));
        $this->assertSame(1, $this->audits('site.reference_archived'));
        $this->assertSame(3, $this->audits('site.reference_name_approval'));
    }

    public function test_renaming_drops_the_name_approval(): void
    {
        $id = $this->ref('dkm')->id;
        $this->approveName($id);
        $this->publish($id);
        $this->as($this->owner)->post("/app/website/references/{$id}", ['name' => 'DKM Holding', 'slug' => 'dkm', 'short_description' => 'Kurumsal Web Platformu', 'active' => 1])->assertSessionHasNoErrors();
        $this->assertFalse((bool) $this->ref('dkm')->client_name_publication_approved);
        $this->get('/referanslar')->assertDontSee('DKM Holding');
    }

    public function test_homepage_shows_only_featured_references_in_panel_order(): void
    {
        foreach (['igs', 'dost-tv', 'pazarradar'] as $slug) {
            $this->approveName($this->ref($slug)->id);
            $this->publish($this->ref($slug)->id);
        }
        $rows = [$this->ref('igs')->id => ['order' => 30, 'featured' => 1], $this->ref('dost-tv')->id => ['order' => 0, 'featured' => 0], $this->ref('pazarradar')->id => ['order' => 10, 'featured' => 1]];
        $this->as($this->owner)->post('/app/website/references/arrange', ['rows' => $rows])->assertSessionHasNoErrors();

        $home = (string) $this->get('/')->getContent();
        $strip = substr($home, (int) strpos($home, 'ref-strip'), 3000);
        $this->assertLessThan(strpos($strip, '>IGS<'), strpos($strip, '>PazarRadar<'), 'PazarRadar (10) before IGS (30).');
        $this->assertStringNotContainsString('Dost TV', $strip, 'Not featured: not on the homepage.');
        $this->get('/referanslar')->assertSee('Dost TV');
        $this->assertSame(3, $this->audits('site.reference_featured_changed'));
    }

    public function test_logos_are_validated_and_shown_only_with_logo_approval(): void
    {
        $id = $this->ref('motioncurate')->id;
        $this->approveName($id);
        $this->publish($id);
        $upload = fn (UploadedFile $f, string $variant = 'light', string $alt = 'MotionCurate logosu') => $this->as($this->owner)->post("/app/website/references/{$id}/logo", ['variant' => $variant, 'logo' => $f, 'alt' => $alt]);

        $upload(UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'))->assertSessionHasErrors('action');
        $upload(UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1; ?>'))->assertSessionHasErrors('action');
        $upload(UploadedFile::fake()->image('logo.png', 400, 120), alt: '')->assertSessionHasErrors('alt');
        $this->assertNull($this->ref('motioncurate')->logo_media_id);

        $upload(UploadedFile::fake()->image('logo.png', 400, 120))->assertSessionHasNoErrors();
        $media = (string) $this->ref('motioncurate')->logo_media_id;
        $this->get('/referanslar')->assertDontSee("/media/{$media}/")->assertSee('<span class="ref-wordmark">MotionCurate</span>', false);

        $this->as($this->owner)->post("/app/website/references/{$id}/approval", ['what' => 'logo', 'approved' => 1, 'note' => 'Logo için yazılı izin', 'confirm' => 1])->assertSessionHasNoErrors();
        $this->get('/referanslar')->assertSee("/media/{$media}/")->assertSee('alt="MotionCurate logosu"', false);
        $upload(UploadedFile::fake()->image('dark.png', 400, 120), 'dark')->assertSessionHasNoErrors();
        $this->get('/referanslar')->assertSee('prefers-color-scheme: dark', false);

        // A new light logo is not the approved one.
        $upload(UploadedFile::fake()->image('new.png', 500, 150))->assertSessionHasNoErrors();
        $this->assertFalse((bool) $this->ref('motioncurate')->logo_use_approved);
        $this->get('/referanslar')->assertSee('<span class="ref-wordmark">MotionCurate</span>', false);
        $this->assertSame(3, $this->audits('site.reference_logo_changed'));
        $this->assertSame(1, $this->audits('site.reference_logo_approval'));
    }

    public function test_roles_are_separated(): void
    {
        $marketer = $this->addMember($this->site, TenantRole::Marketer);
        $approver = $this->addMember($this->site, TenantRole::Approver);
        $viewer = $this->addMember($this->site, TenantRole::Viewer);
        $id = $this->ref('dost-tv')->id;

        $this->as($viewer)->get('/app/website/references')->assertForbidden();
        $this->as($marketer)->get('/app/website/references')->assertOk()->assertSee('Referans ekle');
        $this->as($marketer)->post('/app/website/references', ['name' => 'Yeni Kurum'])->assertRedirect();
        $this->as($marketer)->post("/app/website/references/{$id}", ['name' => 'Dost TV', 'slug' => 'dost-tv', 'short_description' => 'Yeni açıklama', 'active' => 1])->assertSessionHasNoErrors();
        $this->as($marketer)->post("/app/website/references/{$id}/logo", ['variant' => 'light', 'logo' => UploadedFile::fake()->image('l.png', 300, 100), 'alt' => 'Logo'])->assertSessionHasNoErrors();
        $this->as($marketer)->post("/app/website/references/{$id}/status", ['status' => 'published'])->assertForbidden();
        $this->as($marketer)->post("/app/website/references/{$id}/approval", ['what' => 'name', 'approved' => 1, 'note' => 'x', 'confirm' => 1])->assertForbidden();

        $this->as($approver)->post("/app/website/references/{$id}", ['name' => 'X', 'slug' => 'dost-tv'])->assertForbidden();
        $this->as($approver)->post("/app/website/references/{$id}/approval", ['what' => 'name', 'approved' => 1, 'note' => 'x', 'confirm' => 1])->assertForbidden();
        $this->as($approver)->post("/app/website/references/{$id}/logo", ['variant' => 'light', 'remove' => 1])->assertForbidden();
        $this->approveName($id);
        $this->as($approver)->post("/app/website/references/{$id}/status", ['status' => 'published'])->assertSessionHasNoErrors();
        $this->assertSame('published', $this->ref('dost-tv')->status);

        // Another tenant has no website management at all.
        [$other, $otherOwner] = $this->createTenant('baska');
        $this->actingAs($otherOwner, 'web')->withSession([PanelTenant::SESSION_TENANT => $other->id, PanelRecentAuth::SESSION_KEY => time()])
            ->get('/app/website/references')->assertNotFound();
    }

    public function test_descriptions_cannot_inject_html_or_script(): void
    {
        $id = $this->ref('n-extent')->id;
        $this->as($this->owner)->post("/app/website/references/{$id}", ['name' => 'N-Extent', 'slug' => 'n-extent', 'active' => 1, 'website_url' => 'javascript:alert(1)'])
            ->assertSessionHasErrors('action');
        $this->as($this->owner)->post("/app/website/references/{$id}", ['name' => 'N-Extent', 'slug' => 'n-extent', 'active' => 1,
            'short_description' => '<img src=x onerror=alert(1)> İçerik', 'body' => "<script>alert('b')</script>\n\n**Medya** [x](javascript:alert(1))",
            'capabilities' => "<b>Web</b>\nTeknik SEO", 'industry' => '"><script>alert(2)</script>'])->assertSessionHasNoErrors();
        $this->approveName($id);
        $this->publish($id);

        foreach (['/referanslar', '/referanslar/n-extent', '/'] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert', $html, $url);
            $this->assertStringNotContainsString('<img src=x', $html, $url);
            $this->assertStringNotContainsString('javascript:', $html, $url);
            $this->assertStringNotContainsString('<b>Web</b>', $html, $url);
        }
        $this->get('/referanslar/n-extent')->assertSee('<strong>Medya</strong>', false);
    }

    public function test_slugs_are_unique_and_fixed_once_published(): void
    {
        $this->as($this->owner)->post('/app/website/references', ['name' => 'IGS']);
        $this->assertSame(['igs', 'igs-2'], DB::table('site_references')->where('name', 'IGS')->orderBy('slug')->pluck('slug')->all());
        $copy = $this->ref('igs-2')->id;
        $this->as($this->owner)->post("/app/website/references/{$copy}", ['name' => 'IGS', 'slug' => 'igs'])->assertSessionHasErrors('action');

        $id = $this->ref('igs')->id;
        $this->approveName($id);
        $this->publish($id);
        $this->as($this->owner)->post("/app/website/references/{$id}", ['name' => 'IGS', 'slug' => 'igs-yeni'])->assertSessionHasErrors('action');
        $this->as($this->owner)->post("/app/website/references/{$id}/delete")->assertSessionHasErrors('action');
        $this->as($this->owner)->post("/app/website/references/{$copy}/delete")->assertRedirect('/app/website/references');
        $this->assertNull(DB::table('site_references')->where('id', $copy)->first());
        $this->assertSame(1, $this->audits('site.reference_deleted'));
    }

    public function test_references_link_to_published_case_studies(): void
    {
        $contents = app(SiteContents::class);
        $project = (string) DB::table('site_contents')->where('type', 'project')->where('slug', 'ticimax-nebim-v3-entegrasyonu')->value('id');
        $this->assertTrue(DB::table('site_reference_contents')->where('reference_id', $this->ref('igs')->id)->where('content_id', $project)->exists(), 'Seeded relation.');
        $id = $this->ref('igs')->id;
        $this->approveName($id);
        $this->publish($id);

        // Draft case study: no project link yet.
        $this->get('/')->assertDontSee('Projeyi İncele');
        $contents->update($project, ['excerpt' => 'Ticimax ile Nebim V3 arasında ürün, stok ve sipariş akışı.', 'client_display' => 'Erkek giyim markası'], Actor::system());
        $contents->publish($project, Actor::system());

        $this->get('/')->assertSee('Projeyi İncele')->assertSee('href="/projeler/ticimax-nebim-v3-entegrasyonu"', false);
        $this->get('/referanslar/igs')->assertSee('Ticimax + Nebim V3 entegrasyonu')->assertSee('/hizmetler/nebim-v3-entegrasyonlari');

        // A second case study for the same reference, linked in the panel.
        $second = $contents->create(ContentType::Project, 'Pazarlama Otomasyonu Altyapısı', Actor::system());
        $contents->update($second, ['excerpt' => 'Segment ve akışlar.'], Actor::system());
        $contents->publish($second, Actor::system());
        $this->as($this->owner)->post("/app/website/references/{$id}", ['name' => 'IGS', 'slug' => 'igs', 'short_description' => 'E-Ticaret, ERP ve Pazarlama Otomasyonu', 'active' => 1,
            'contents' => [$project, $second]])->assertSessionHasNoErrors();
        $this->get('/referanslar/igs')->assertSee('Ticimax + Nebim V3 entegrasyonu')->assertSee('Pazarlama Otomasyonu Altyapısı');
    }

    public function test_a_new_reference_goes_live_from_the_panel_alone(): void
    {
        $this->as($this->owner)->post('/app/website/references', ['name' => 'Yeni Müşteri A.Ş.'])->assertRedirect();
        $id = $this->ref('yeni-musteri-as')->id;
        $this->as($this->owner)->post("/app/website/references/{$id}", ['name' => 'Yeni Müşteri A.Ş.', 'slug' => 'yeni-musteri-as', 'short_description' => 'Veri entegrasyonu',
            'capabilities' => "API\nRaporlama", 'active' => 1])->assertSessionHasNoErrors();
        $this->approveName($id);
        $this->publish($id);
        $this->as($this->owner)->post('/app/website/references/arrange', ['rows' => [$id => ['order' => -5, 'featured' => 1]]])->assertSessionHasNoErrors();

        $this->get('/')->assertSee('Yeni Müşteri A.Ş.');
        $this->get('/referanslar')->assertSee('Veri entegrasyonu')->assertSee('Raporlama');
        $this->as($this->owner)->get('/app/website/references')->assertOk()->assertSee('Görünüyor');
        $this->as($this->owner)->get("/app/website/references/{$id}")->assertOk()->assertSee('Müşteri onayları');
    }
}
