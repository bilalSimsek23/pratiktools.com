<?php

namespace Tests\Feature\Site;

use App\Audit\Actor;
use App\Http\Middleware\PanelRecentAuth;
use App\Http\Middleware\PanelTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Site\SiteError;
use App\Site\SiteMedia;
use App\Site\SiteSettings;
use App\Site\SiteStorageCheck;
use App\Tenancy\TenantRole;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Website media on Laravel Cloud's private "site-media" bucket.
 */
final class SiteStorageTest extends TestCase
{
    use InteractsWithTenants;

    private Tenant $site;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // As Laravel Cloud registers an attached bucket (credentials are its business).
        config(['app.url' => 'https://pratiktools.test']);
        config(['filesystems.disks.site-media' => ['driver' => 's3', 'bucket' => 'test-bucket', 'region' => 'auto', 'key' => 'k', 'secret' => 's', 'throw' => false]]);
        Storage::fake('site-media');
        Storage::fake('local');
        [$this->site, $this->owner] = $this->createTenant('pratiktools');
        $this->artisan('site:setup', ['--tenant' => 'pratiktools'])->assertSuccessful();
    }

    private function as(User $user): self
    {
        return $this->actingAs($user, 'web')->withSession([PanelTenant::SESSION_TENANT => $this->site->id, PanelRecentAuth::SESSION_KEY => time()]);
    }

    private function upload(UploadedFile $file, ?User $user = null): TestResponse
    {
        return $this->as($user ?? $this->owner)->post('/app/website/media', ['image' => $file, 'alt' => 'Test görseli']);
    }

    public function test_site_media_is_detected_and_the_app_default_disk_stays_local(): void
    {
        $this->assertSame('site-media', app(SiteSettings::class)->mediaDisk(), 'Cloud disk selected without any saved setting.');
        $this->assertTrue(SiteSettings::persistentDisk('site-media'));
        putenv('FILESYSTEM_DISK=site-media');
        try {
            $this->assertSame('local', config('filesystems.default'), 'An attached bucket marked default does not capture implicit storage.');
        } finally {
            putenv('FILESYSTEM_DISK');
        }
        $this->as($this->owner)->get('/app/website/settings')->assertOk()->assertSee('site-media (s3)');
        $this->as($this->owner)->get('/app/website/media')->assertOk()->assertSee('<code>site-media</code>', false)->assertDontSee('Kalıcı değil');
    }

    public function test_upload_is_reencoded_to_site_media_and_served_from_our_origin(): void
    {
        $this->upload(UploadedFile::fake()->image('ok.png', 1200, 600))->assertSessionHasNoErrors();
        $m = DB::table('site_media')->sole();
        $this->assertSame('site-media', $m->disk);
        $variants = json_decode((string) $m->variants, true);
        foreach ([...array_values($variants['webp']), ...array_values($variants['jpg'])] as $key) {
            $this->assertStringStartsWith("site-media/{$m->id}/", $key, 'Content-addressed by media id.');
            Storage::disk('site-media')->assertExists($key);
        }
        Storage::disk('local')->assertDirectoryEmpty('/');

        $r = $this->get("/media/{$m->id}/800.webp")->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        $this->assertSame(Storage::disk('site-media')->get($variants['webp']['800']), $r->streamedContent());
        $this->assertStringNotContainsString('test-bucket', (string) $r->headers);
    }

    public function test_invalid_oversized_and_disguised_files_are_rejected_and_nothing_is_stored(): void
    {
        $this->upload(UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))->assertSessionHasErrors('action');
        $this->upload(UploadedFile::fake()->createWithContent('shell.png', "\x89PNG\r\n<?php system('id'); ?>"))->assertSessionHasErrors('action');
        $this->upload(UploadedFile::fake()->createWithContent('a.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')))->assertSessionHasErrors('action');
        $this->upload(UploadedFile::fake()->create('big.jpg', 6000, 'image/jpeg'))->assertSessionHasErrors('image');
        config(['site.media_max_pixels' => 1000]);
        $this->upload(UploadedFile::fake()->image('wide.png', 100, 100))->assertSessionHasErrors('action');

        $this->assertSame(0, DB::table('site_media')->count());
        $this->assertSame([], Storage::disk('site-media')->allFiles());
    }

    public function test_reference_logo_replace_and_remove_leave_no_orphans(): void
    {
        $ref = (string) DB::table('site_references')->where('slug', 'pazarradar')->value('id');
        $logo = fn (string $name, int $w) => $this->as($this->owner)->post("/app/website/references/{$ref}/logo", ['variant' => 'light', 'logo' => UploadedFile::fake()->image($name, $w, 100), 'alt' => 'Logo']);

        $logo('a.png', 300)->assertSessionHasNoErrors();
        $first = (string) DB::table('site_references')->where('id', $ref)->value('logo_media_id');
        $firstFiles = Storage::disk('site-media')->allFiles("site-media/{$first}");
        $this->assertNotEmpty($firstFiles);

        $logo('b.png', 320)->assertSessionHasNoErrors();
        $second = (string) DB::table('site_references')->where('id', $ref)->value('logo_media_id');
        $this->assertNotSame($first, $second, 'Replacement gets a new id, so a new URL (no stale cache).');
        $this->assertNull(DB::table('site_media')->where('id', $first)->first(), 'Old logo removed after the switch.');
        $this->assertSame([], Storage::disk('site-media')->allFiles("site-media/{$first}"));
        $this->assertNotEmpty(Storage::disk('site-media')->allFiles("site-media/{$second}"));

        $this->as($this->owner)->post("/app/website/references/{$ref}/logo", ['variant' => 'light', 'remove' => 1])->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('site_media')->count());
        $this->assertSame([], Storage::disk('site-media')->allFiles());
    }

    public function test_media_in_use_cannot_be_deleted_and_unused_media_needs_authorization(): void
    {
        $this->upload(UploadedFile::fake()->image('x.png', 400, 200))->assertSessionHasNoErrors();
        $id = (string) DB::table('site_media')->value('id');
        $service = (string) DB::table('site_contents')->where('slug', 'teknik-seo')->value('id');
        DB::table('site_contents')->where('id', $service)->update(['social_image_id' => $id]);

        $this->as($this->owner)->post("/app/website/media/{$id}/delete")->assertSessionHasErrors('action');
        DB::table('site_contents')->where('id', $service)->update(['social_image_id' => null]);
        $approver = $this->addMember($this->site, TenantRole::Approver);
        $this->as($approver)->post("/app/website/media/{$id}/delete")->assertForbidden();
        $this->as($this->owner)->post("/app/website/media/{$id}/delete")->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('site_media')->count());
        $this->assertSame([], Storage::disk('site-media')->allFiles());
        $this->assertSame(1, $this->inTenant($this->site, fn () => DB::table('audit_logs')->where('action', 'site.media_deleted')->count()));
    }

    public function test_unauthorized_upload_is_rejected(): void
    {
        $this->post('/app/website/media', ['image' => UploadedFile::fake()->image('x.png', 200, 200), 'alt' => 'x'])->assertRedirect(route('panel.login'));
        $this->upload(UploadedFile::fake()->image('x.png', 200, 200), $this->addMember($this->site, TenantRole::Approver))->assertForbidden();
        $this->upload(UploadedFile::fake()->image('x.png', 200, 200), $this->addMember($this->site, TenantRole::Viewer))->assertForbidden();
        $this->assertSame([], Storage::disk('site-media')->allFiles());
    }

    public function test_unavailable_storage_fails_safely_without_half_written_state(): void
    {
        // Writes refused (e.g. bucket unreachable or read-only key).
        $refusing = \Mockery::mock(Filesystem::class);
        $refusing->allows('put')->andReturn(true, false);
        $refusing->allows('delete')->andReturn(true);
        Storage::set('site-media', $refusing);
        try {
            app(SiteMedia::class)->upload(UploadedFile::fake()->image('x.png', 900, 400), 'alt', Actor::system());
            $this->fail('Expected a storage error.');
        } catch (SiteError $e) {
            $this->assertStringContainsString('Depolama', $e->getMessage());
        }
        $this->assertSame(0, DB::table('site_media')->count(), 'No row for a half-written upload.');
        $refusing->shouldHaveReceived('delete')->once();

        // Reads failing: the image answers 503, never details.
        Storage::fake('site-media');
        $this->upload(UploadedFile::fake()->image('ok.png', 400, 200))->assertSessionHasNoErrors();
        $id = (string) DB::table('site_media')->value('id');
        $broken = \Mockery::mock(Filesystem::class);
        $broken->allows('readStream')->andThrow(new \RuntimeException('connect timeout to bucket endpoint'));
        Storage::set('site-media', $broken);
        $r = $this->get("/media/{$id}/400.webp")->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString('bucket', (string) $r->getContent());

        // Production refuses a non-persistent disk.
        $this->as($this->owner)->post('/app/website/settings/media', ['disk' => 'local'])->assertSessionHasNoErrors();
        $this->app->detectEnvironment(fn () => 'production');
        try {
            $this->expectException(SiteError::class);
            app(SiteMedia::class)->upload(UploadedFile::fake()->image('y.png', 300, 300), 'alt', Actor::system());
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_storage_round_trip_check_leaves_nothing_and_reveals_no_secret(): void
    {
        $this->artisan('site:storage-check')->assertSuccessful()->expectsOutputToContain('Storage check PASS')->doesntExpectOutputToContain('test-bucket');
        $this->assertSame([], Storage::disk('site-media')->allFiles(), 'Test object removed.');

        $r = app(SiteStorageCheck::class)->run(Actor::system());
        $this->assertTrue($r['passed']);
        $this->assertSame(['configured', 'write', 'exists', 'read_hash', 'delete', 'gone'], array_keys($r['steps']));
        $this->assertStringNotContainsString('"s"', (string) json_encode($r));

        $this->as($this->owner)->post('/app/website/storage-check')->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->as($this->addMember($this->site, TenantRole::Marketer))->post('/app/website/storage-check')->assertForbidden();

        $down = \Mockery::mock(Filesystem::class);
        $down->allows('put')->andThrow(new \RuntimeException('unreachable'));
        $down->allows('delete')->andReturn(true);
        $down->allows('exists')->andReturn(false);
        Storage::set('site-media', $down);
        $this->artisan('site:storage-check')->assertFailed()->expectsOutputToContain('FAIL');
    }
}
