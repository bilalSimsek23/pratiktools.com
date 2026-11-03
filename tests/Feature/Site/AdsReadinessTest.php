<?php

namespace Tests\Feature\Site;

use App\Audit\Actor;
use App\Http\Middleware\PanelRecentAuth;
use App\Http\Middleware\PanelTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Site\AdsContent;
use App\Site\ContentStatus;
use App\Site\InitialContent;
use App\Site\MediaCatalog;
use App\Site\SiteContents;
use App\Site\SiteInquiries;
use App\Site\SiteSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Google Ads readiness: conversion events and consent, the lead
 * readiness check, public list cleanup and the
 * AdsContent pack (panel edits win, drafts stay drafts).
 */
final class AdsReadinessTest extends TestCase
{
    use InteractsWithTenants;

    private const ORIGIN = 'https://pratiktools.test';

    private Tenant $site;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['app.url' => self::ORIGIN, 'site.owner_tenant' => null]);
        [$this->site, $this->owner] = $this->createTenant('pratiktools');
        $this->artisan('site:setup', ['--tenant' => 'pratiktools'])->assertSuccessful();
        InitialContent::install();
        MediaCatalog::install();
    }

    private function as(User $user): self
    {
        return $this->actingAs($user, 'web')->withSession([PanelTenant::SESSION_TENANT => $this->site->id, PanelRecentAuth::SESSION_KEY => time()]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function inquire(array $fields = []): TestResponse
    {
        $body = $fields + ['name' => 'Ayşe Yılmaz', 'email' => 'ayse@example.com', 'message' => 'Ticimax ile Nebim entegrasyonu istiyoruz.', 'kind' => 'teklif',
            'ft' => SiteInquiries::stamp(time() - 30), 'website' => ''];

        return $this->call('POST', '/iletisim/talep', $body, [], [], ['HTTP_ORIGIN' => self::ORIGIN, 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '198.51.100.'.random_int(1, 250)]);
    }

    private function inquiries(): int
    {
        return $this->inTenant($this->site, fn () => DB::table('lead_inquiries')->count());
    }

    public function test_conversion_labels_are_validated_settings_and_reach_the_page_config(): void
    {
        $this->as($this->owner)->post('/app/website/settings/analytics', ['ga4_id' => 'G-ABC1234567', 'google_ads_id' => 'AW-123456789', 'google_ads_lead_label' => 'LeadLbl_1',
            'google_ads_phone_label' => 'PhoneLbl-2', 'google_ads_email_label' => 'MailLbl3'])->assertSessionHasNoErrors();
        $this->assertSame(['PhoneLbl-2', 'MailLbl3'], array_values(array_intersect_key(app(SiteSettings::class)->get('analytics'), array_flip(['google_ads_phone_label', 'google_ads_email_label']))));
        $page = (string) $this->get('/')->getContent();
        $this->assertStringContainsString('"google_ads_phone_label":"PhoneLbl-2"', $page);
        $this->assertStringContainsString('data-consent-reset', $page, 'The consent choice can be changed later.');

        $this->as($this->owner)->post('/app/website/settings/analytics', ['google_ads_phone_label' => 'bad label!'])->assertSessionHasErrors();
        $this->assertSame('PhoneLbl-2', app(SiteSettings::class)->get('analytics')['google_ads_phone_label']);
    }

    public function test_ctas_phone_and_email_are_separate_tracked_events(): void
    {
        $this->as($this->owner)->post('/app/website/settings/organization', ['name' => 'PratikTools', 'email' => 'info@pratiktools.test', 'phone' => '+90 555 000 00 00'])->assertSessionHasNoErrors();
        $home = (string) $this->get('/')->getContent();
        $this->assertStringContainsString('data-track="cta_click" data-cta="header"', $home);
        $this->assertStringContainsString('data-track="cta_click" data-cta="home_hero"', $home);
        $this->assertStringContainsString('href="tel:+905550000000" data-track="phone_click"', $home);
        $this->assertStringContainsString('href="mailto:info@pratiktools.test" data-track="email_click"', $home);
        $this->assertStringNotContainsString('data-consent-reset', $home, 'No analytics provider: nothing to consent to.');
        $this->assertStringContainsString('data-track="cta_click" data-cta="service_hero"', (string) $this->get('/hizmetler/ticimax-entegrasyonlari')->getContent());

        $beacon = fn (string $e) => $this->call('POST', '/t', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['e' => $e, 'p' => '/iletisim']))->assertNoContent();
        foreach (['cta_click', 'phone_click', 'email_click', 'nope'] as $e) {
            $beacon($e);
        }
        $this->assertSame(['cta_click', 'email_click', 'phone_click'], DB::table('site_events_daily')->orderBy('event')->pluck('event')->all());
    }

    public function test_lead_check_reports_readiness_without_printing_ids(): void
    {
        $this->artisan('site:lead-check')->expectsOutputToContain('Lead check FAIL.')->assertFailed();

        $this->as($this->owner)->post('/app/website/settings/analytics', ['ga4_id' => 'G-ABC1234567', 'google_ads_id' => 'AW-123456789', 'google_ads_lead_label' => 'LeadLbl_1'])->assertSessionHasNoErrors();
        $this->artisan('site:lead-check')->expectsOutputToContain('Lead check PASS.')
            ->doesntExpectOutputToContain('G-ABC1234567')->doesntExpectOutputToContain('LeadLbl_1')->assertSuccessful();
    }

    public function test_a_stored_inquiry_counts_lead_submit_and_reaches_the_panel(): void
    {
        $first = json_encode(['landing' => '/hizmetler/pazaryeri-entegrasyonlari', 'source' => 'google', 'medium' => 'cpc', 'campaign' => 'pazaryeri-q4', 'gclid' => 1]);
        $this->inquire(['first_touch' => $first, 'last_touch' => $first, 'interest' => 'service:ticimax-entegrasyonlari'])->assertOk()->assertJson(['status' => 'accepted']);
        $this->assertSame(1, $this->inquiries());
        $this->assertSame(1, (int) DB::table('site_events_daily')->where('event', 'lead_submit')->where('path', '/hizmetler/pazaryeri-entegrasyonlari')->sum('count'));
        $this->as($this->owner)->get('/app/website/leads')->assertOk()->assertSee('Ayşe Yılmaz')->assertSee('pazaryeri-q4');
        Http::assertNothingSent();
    }

    public function test_public_lists_hide_empty_categories_and_do_not_repeat_the_category(): void
    {
        $list = (string) $this->get('/hizmetler')->getContent();
        $this->assertStringNotContainsString('Bu alandaki çalışmalarımız için', $list);
        $this->assertStringNotContainsString('id="web-uygulamalari"', $list, 'Empty category hidden.');
        $this->assertStringContainsString('id="ticimax-entegrasyonlari"', $list);
        $this->assertStringNotContainsString('<p class="eyebrow">Ticimax Entegrasyonları</p>', $list, 'The card does not repeat its section heading.');
        $home = (string) $this->get('/')->getContent();
        $this->assertStringNotContainsString('/hizmetler#web-uygulamalari', $home, 'Home grid and footer skip empty categories.');
    }

    public function test_ads_content_dry_run_writes_nothing_and_apply_is_idempotent(): void
    {
        $before = DB::table('site_contents')->count();
        $this->artisan('site:ads-content')->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame($before, DB::table('site_contents')->count());

        // A field the panel changed is kept.
        DB::table('site_contents')->where('type', 'service')->where('slug', 'push-notification-altyapisi')->update(['excerpt' => 'Panelde yazıldı.']);

        $this->artisan('site:ads-content', ['--apply' => true])->expectsOutputToContain('1 field(s) kept')->assertSuccessful();
        $status = fn (string $type, string $slug) => DB::table('site_contents')->where('type', $type)->where('slug', $slug)->value('status');
        $this->assertSame(ContentStatus::Published->value, $status('service', 'pazaryeri-entegrasyonlari'));
        $this->assertSame(ContentStatus::Published->value, $status('service', 'ozel-yazilim-ve-operasyon-araclari'));
        $this->assertSame(ContentStatus::Published->value, $status('project', 'motioncurate-toplu-video-ve-render-otomasyonu'));
        $this->assertSame(5, DB::table('site_contents')->where('type', 'article')->where('status', ContentStatus::Draft->value)->count());
        $this->assertSame(0, DB::table('site_contents')->where('type', 'article')->where('status', ContentStatus::Published->value)->count());
        $this->assertSame('Panelde yazıldı.', DB::table('site_contents')->where('slug', 'push-notification-altyapisi')->value('excerpt'));

        // Corrections: no "demo" promise, ViA Frame wording, Render Farm featured on home instead of e-mail marketing.
        $email = (string) $this->get('/cozumler/email-marketing-otomasyonu')->getContent();
        $this->assertStringNotContainsString('Demo talep', $email);
        $this->assertStringContainsString('ViA Frame', (string) $this->get('/hizmetler/iys-via-entegrasyonu')->getContent());
        $home = (string) $this->get('/')->getContent();
        $this->assertStringContainsString('/cozumler/render-farm-kurulumu', $home);
        $this->assertStringNotContainsString('/cozumler/email-marketing-otomasyonu', $home);
        $this->assertStringContainsString('Gerçek projeler, çözdüğümüz problemler', $home);
        $this->assertStringNotContainsString('/hizmetler#veri-ve-raporlama', $home);

        // Case studies: anonymous client, MotionCurate as PratikTools' own product, no client name leaked.
        $case = (string) $this->get('/projeler/ticimax-nebim-cok-kanalli-operasyon')->getContent();
        $this->assertStringContainsString('çok kanallı bir perakende markası', $case);
        $this->assertStringNotContainsString('IGS', $case);
        $this->assertStringContainsString('kendi ürünüdür; bir müşteri projesi değildir', (string) $this->get('/projeler/motioncurate-toplu-video-ve-render-otomasyonu')->getContent());

        // Internal links and the related project block on service pages.
        $ticimax = (string) $this->get('/hizmetler/ticimax-entegrasyonlari')->getContent();
        $this->assertStringContainsString('Bu alandaki projelerimiz', $ticimax);
        $this->assertStringContainsString('/projeler/ticimax-nebim-cok-kanalli-operasyon', $ticimax);
        $this->assertStringContainsString('/hizmetler/pazaryeri-entegrasyonlari', $ticimax);
        $this->assertStringContainsString('Nasıl çalışıyor?', (string) $this->get('/cozumler/ticimax-nebim-entegrasyonu')->getContent());
        $this->assertStringContainsString('id="pazaryeri-entegrasyonlari"', (string) $this->get('/hizmetler')->getContent(), 'The category is no longer empty.');

        // Again: nothing created or changed.
        $count = DB::table('site_contents')->count();
        $rows = app(AdsContent::class)->run(true, Actor::system());
        $this->assertSame($count, DB::table('site_contents')->count());
        $this->assertSame([], array_values(array_filter($rows, fn ($r) => in_array($r['action'], ['create', 'publish', 'update', 'add', 'swap'], true))));
    }

    public function test_a_case_study_does_not_publish_a_client_name_without_approval(): void
    {
        $this->artisan('site:ads-content', ['--apply' => true])->assertSuccessful();
        $id = (string) DB::table('site_contents')->where('slug', 'ticimax-nebim-cok-kanalli-operasyon')->value('id');
        app(SiteContents::class)->update($id, ['client_name' => 'Gizli Marka', 'client_named' => '1'], Actor::system());
        $this->assertStringNotContainsString('Gizli Marka', (string) $this->get('/projeler/ticimax-nebim-cok-kanalli-operasyon')->getContent());
        $this->assertStringNotContainsString('Gizli Marka', (string) $this->get('/')->getContent());
    }
}
