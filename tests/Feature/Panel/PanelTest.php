<?php

namespace Tests\Feature\Panel;

use App\Audit\Actor;
use App\Campaigns\Campaigns;
use App\Email\EmailMessageType;
use App\Email\Templates\EmailTemplates;
use App\Http\Middleware\PanelRecentAuth;
use App\Http\Middleware\PanelTenant;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Segments\Segments;
use App\Tenancy\TenantRole;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithAutomation;
use Tests\Concerns\InteractsWithSes;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class PanelTest extends TestCase
{
    use InteractsWithAutomation, InteractsWithSes, InteractsWithTenants;

    private Tenant $tenant;

    private User $owner;

    private TenantIntegration $ses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSes();
        Queue::fake();
        [$this->tenant, $this->owner] = $this->createTenant('shop');
        $this->ses = $this->connectSes($this->tenant);
        $this->senderIdentity($this->tenant, $this->ses);
    }

    private function as(User $user, ?Tenant $tenant = null): self
    {
        return $this->actingAs($user, 'web')->withSession([PanelTenant::SESSION_TENANT => ($tenant ?? $this->tenant)->id, PanelRecentAuth::SESSION_KEY => time()]);
    }

    private function campaign(string $name = 'Kasım'): string
    {
        return $this->inTenant($this->tenant, function () use ($name) {
            $segment = app(Segments::class)->create('S'.uniqid(), 'dynamic', ['all' => [['field' => 'order_count', 'op' => 'gte', 'value' => 0]]], Actor::system());

            return app(Campaigns::class)->create(['name' => $name, 'type' => 'newsletter', 'segment_id' => $segment], Actor::system());
        });
    }

    public function test_root_redirects_to_login_without_a_session_and_to_dashboard_with_one(): void
    {
        $this->get('/')->assertRedirect(route('panel.dashboard'));
        $this->get('/app')->assertRedirect(route('panel.login'));
        $this->get('/app/login')->assertOk()->assertSee('Giriş');

        $this->as($this->owner)->get('/')->assertRedirect(route('panel.dashboard'));
        $this->as($this->owner)->get('/app')->assertOk()->assertSee('Genel bakış')->assertSee('Harici engeller');
    }

    public function test_login_enters_the_only_tenant_and_wrong_password_is_refused(): void
    {
        $this->post('/app/login', ['email' => $this->owner->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->assertGuest('web');

        $this->post('/app/login', ['email' => $this->owner->email, 'password' => self::PASSWORD])->assertRedirect(route('panel.dashboard'));
        $this->assertAuthenticatedAs($this->owner, 'web');
        $this->assertSame($this->tenant->id, session(PanelTenant::SESSION_TENANT));
        $this->get('/app/campaigns')->assertOk();
    }

    public function test_csrf_is_required_for_panel_forms(): void
    {
        // The framework skips the check under unit tests; enforce it here.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->as($this->owner)->post('/app/templates', ['name' => 'X', 'message_type' => 'marketing'])->assertStatus(419);
        $this->post('/app/login', ['email' => $this->owner->email, 'password' => self::PASSWORD])->assertStatus(419);
    }

    public function test_every_screen_renders_for_the_owner(): void
    {
        $campaign = $this->campaign();
        foreach (['/app', '/app/campaigns', '/app/campaigns/new', "/app/campaigns/{$campaign}", '/app/approvals', '/app/dispatches', '/app/templates', '/app/senders',
            '/app/segments', '/app/automations', '/app/forms', '/app/leads', '/app/integrations', '/app/readiness', '/app/suppressions', '/app/settings'] as $path) {
            $this->as($this->owner)->get($path)->assertOk();
        }
    }

    public function test_user_content_is_escaped(): void
    {
        $campaign = $this->campaign('<script>alert("x")</script>');

        $this->as($this->owner)->get('/app/campaigns')->assertOk()->assertDontSee('<script>alert("x")</script>', false)->assertSee('&lt;script&gt;', false);
        $this->as($this->owner)->get("/app/campaigns/{$campaign}")->assertOk()->assertDontSee('<script>alert("x")</script>', false);
    }

    public function test_rbac_hides_and_refuses_what_a_role_cannot_do(): void
    {
        $viewer = $this->addMember($this->tenant, TenantRole::Viewer);
        $marketer = $this->addMember($this->tenant, TenantRole::Marketer);
        $approver = $this->addMember($this->tenant, TenantRole::Approver);
        $campaign = $this->campaign();

        $this->as($viewer)->get('/app')->assertOk()->assertDontSee('href="'.route('panel.campaigns').'"', false)->assertDontSee(route('panel.campaigns.create'));
        $this->as($viewer)->get('/app/campaigns')->assertForbidden();
        $this->as($marketer)->get('/app/suppressions')->assertForbidden();
        $this->as($marketer)->post("/app/campaigns/{$campaign}/transition", ['to' => 'ready'])->assertForbidden();
        $this->as($marketer)->post('/app/settings/sending-mode', ['mode' => 'internal'])->assertForbidden();
        $this->as($approver)->post('/app/campaigns', ['name' => 'X', 'type' => 'newsletter'])->assertForbidden();
        $this->as($approver)->post("/app/campaigns/{$campaign}/approvals")->assertForbidden();
        $this->as($marketer)->post('/app/templates', ['name' => 'Bülten', 'message_type' => 'marketing'])->assertRedirect();
    }

    public function test_sensitive_actions_require_recent_password_confirmation(): void
    {
        $campaign = $this->campaign();
        $this->actingAs($this->owner, 'web')->withSession([PanelTenant::SESSION_TENANT => $this->tenant->id, PanelRecentAuth::SESSION_KEY => time() - 3600])
            ->post("/app/campaigns/{$campaign}/transition", ['to' => 'ready'])->assertRedirect(route('panel.confirm'));
        $this->post('/app/confirm', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post('/app/confirm', ['password' => self::PASSWORD])->assertRedirect();
        $this->assertGreaterThan(time() - 5, session(PanelRecentAuth::SESSION_KEY));
    }

    public function test_cross_tenant_access_is_denied(): void
    {
        [$other, $otherOwner] = $this->createTenant('other');
        $campaign = $this->campaign();

        // A session pointing at a tenant the user is not a member of is refused.
        $this->as($otherOwner, $this->tenant)->get('/app')->assertRedirect(route('panel.tenants'));
        // Ids of another tenant are invisible.
        $this->as($otherOwner, $other)->get("/app/campaigns/{$campaign}")->assertNotFound();
        $this->as($otherOwner, $other)->post("/app/campaigns/{$campaign}/approvals")->assertNotFound();
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => DB::table('campaign_approvals')->count()));
    }

    public function test_integrations_screen_never_shows_secrets(): void
    {
        $encrypted = $this->inTenant($this->tenant, fn () => (string) DB::table('tenant_integrations')->where('id', $this->ses->id)->value('encrypted_secret'));
        config(['email.unsubscribe.key' => null]);

        /** @var TestResponse $page */
        $page = $this->as($this->owner)->get('/app/integrations')->assertOk()->assertSee('Amazon SES')->assertSee('Add EMAIL_UNSUBSCRIBE_KEY as Laravel Cloud Secret.');
        foreach ([self::SES_SECRET, self::SES_ACCESS_KEY, $encrypted] as $secret) {
            $page->assertDontSee($secret, false);
        }
        $this->as($this->owner)->get('/app/readiness')->assertOk()->assertSee('Email Unsubscribe Key')->assertSee('Ses Production Access');
    }

    public function test_settings_offer_only_off_and_test_only(): void
    {
        $this->as($this->owner)->get('/app/settings')->assertOk()->assertSee('Live sending is disabled until external readiness requirements are satisfied.');
        $this->as($this->owner)->post('/app/settings/sending-mode', ['mode' => 'live'])->assertSessionHasErrors('action');
        $this->assertSame('off', $this->inTenant($this->tenant, fn () => DB::table('tenant_settings')->value('sending_mode')));
        $this->as($this->owner)->post('/app/settings/sending-mode', ['mode' => 'internal'])->assertSessionHasNoErrors();
        $this->assertSame('internal', $this->inTenant($this->tenant, fn () => DB::table('tenant_settings')->value('sending_mode')));
    }

    public function test_preview_frame_is_sandboxed(): void
    {
        $version = $this->inTenant($this->tenant, function () {
            $t = app(EmailTemplates::class);
            $id = $t->create('Önizleme', EmailMessageType::Marketing, Actor::system());

            return $t->addVersion($id, 'Konu', '<p>Merhaba</p><a href="{{ unsubscribe_url }}">Çık</a>', 'Merhaba {{ unsubscribe_url }}', Actor::system());
        });

        $frame = $this->as($this->owner)->get("/app/template-versions/{$version}/frame")->assertOk();
        $this->assertStringContainsString('sandbox', (string) $frame->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("default-src 'none'", (string) $frame->headers->get('Content-Security-Policy'));
        $this->assertNull($frame->headers->get('X-Panel-Preview-Frame'));
        $this->assertSame([], $this->sesRequests);
    }

    public function test_panel_pages_send_a_nonce_csp_without_inline_scripts(): void
    {
        $page = $this->as($this->owner)->get('/app')->assertOk();
        $csp = (string) $page->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString("'unsafe-inline'", $csp);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*nonce=)/i', (string) $page->getContent());
        $this->assertDoesNotMatchRegularExpression('/\sstyle="/i', (string) $page->getContent());
    }
}
