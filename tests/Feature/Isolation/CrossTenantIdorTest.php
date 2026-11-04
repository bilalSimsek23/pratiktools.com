<?php

namespace Tests\Feature\Isolation;

use App\Audit\Actor;
use App\Automation\Automations;
use App\Automation\AutomationTemplates;
use App\Campaigns\Campaigns;
use App\Http\Middleware\PanelRecentAuth;
use App\Http\Middleware\PanelTenant;
use App\Leads\LeadForms;
use App\Models\Tenant;
use App\Models\User;
use App\Segments\Segments;
use App\Tenancy\TenantRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Two customers on one PratikTools: "IGS Test" and "ABC Tekstil Test", each
 * with users, leads, campaigns, segments and automations. A user of one
 * tenant must not reach the other's records even with their UUIDs: every
 * attempt answers 403/404 and changes nothing. RLS is checked directly too.
 */
#[Group('tenant-isolation')]
final class CrossTenantIdorTest extends TestCase
{
    use InteractsWithTenants;

    private const ORIGIN = 'https://shop.example';

    /** @var array<string, array{tenant: Tenant, owner: User, marketer: User, segment: string, campaign: string, automation: string, form: string, lead: string}> */
    private array $t = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        foreach (['igs-test' => 'IGS Test', 'abc-tekstil-test' => 'ABC Tekstil Test'] as $slug => $name) {
            [$tenant, $owner] = $this->createTenant($slug);
            $this->inTenant($tenant, fn () => Tenant::query()->whereKey($tenant->id)->update(['name' => $name]));
            $marketer = $this->addMember($tenant, TenantRole::Marketer);
            $data = $this->inTenant($tenant, function () use ($name) {
                $segment = app(Segments::class)->create("{$name} segmenti", 'dynamic', ['all' => [['field' => 'order_count', 'op' => 'gte', 'value' => 0]]], Actor::system());
                $campaign = app(Campaigns::class)->create(['name' => "{$name} kampanyası", 'type' => 'newsletter', 'segment_id' => $segment], Actor::system());
                $d = AutomationTemplates::abandonedCart(30);
                $automation = app(Automations::class)->create("{$name} otomasyonu", $d['trigger'], $d['steps'], $d['settings'], Actor::system());
                $forms = app(LeadForms::class);
                $form = $forms->create("{$name} formu", 'footer', 'Kampanyalardan e-posta ile haberdar olmak istiyorum.', 'Teşekkürler.', [self::ORIGIN], Actor::system());
                $forms->setActive($form, true, Actor::system());

                return compact('segment', 'campaign', 'automation', 'form');
            });
            $lead = Str::lower(Str::before($slug, '-')).'.lead@example.com';
            $public = $this->inTenant($tenant, fn () => (string) DB::table('lead_forms')->where('id', $data['form'])->value('public_id'));
            $this->call('POST', "/public/v1/forms/{$public}/subscribe", [], [], [], ['HTTP_ORIGIN' => self::ORIGIN, 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.'.random_int(1, 250)],
                (string) json_encode(['email' => $lead, 'consent' => true]))->assertStatus(202);
            $this->t[$slug] = ['tenant' => $tenant, 'owner' => $owner, 'marketer' => $marketer, 'lead' => $lead] + $data;
        }
    }

    private function as(User $user, Tenant $tenant): self
    {
        return $this->actingAs($user, 'web')->withSession([PanelTenant::SESSION_TENANT => $tenant->id, PanelRecentAuth::SESSION_KEY => time()]);
    }

    public function test_each_tenant_user_sees_only_their_own_records(): void
    {
        foreach (['igs-test' => 'abc-tekstil-test', 'abc-tekstil-test' => 'igs-test'] as $mine => $theirs) {
            $me = $this->t[$mine];
            $other = $this->t[$theirs];
            $otherName = $other['tenant']->slug === 'igs-test' ? 'IGS Test' : 'ABC Tekstil Test';
            foreach (['/app/campaigns', '/app/segments', '/app/automations', '/app/forms', '/app/leads', '/app/'] as $page) {
                $r = $this->as($me['owner'], $me['tenant'])->get($page)->assertOk();
                $r->assertDontSee($otherName.' kampanyası')->assertDontSee($otherName.' segmenti')->assertDontSee($otherName.' otomasyonu')->assertDontSee($otherName.' formu')
                    ->assertDontSee($other['lead']);
            }
            $this->as($me['owner'], $me['tenant'])->get('/app/campaigns')->assertSee(($me['tenant']->slug === 'igs-test' ? 'IGS Test' : 'ABC Tekstil Test').' kampanyası');
        }
    }

    public function test_using_the_other_tenants_uuids_in_urls_is_refused_and_changes_nothing(): void
    {
        $a = $this->t['igs-test'];
        $b = $this->t['abc-tekstil-test'];
        foreach ([$a['owner'], $a['marketer']] as $user) {
            $as = fn () => $this->as($user, $a['tenant']);
            foreach (["/app/campaigns/{$b['campaign']}", "/app/campaigns/{$b['campaign']}/edit", "/app/campaigns/{$b['campaign']}/dispatches/new",
                "/app/automations/{$b['automation']}", "/app/forms/{$b['form']}"] as $url) {
                $status = $as()->get($url)->status();
                $this->assertContains($status, [403, 404], "GET {$url} answered {$status}");
            }
            foreach ([["/app/campaigns/{$b['campaign']}", ['name' => 'Ele geçirildi', 'type' => 'newsletter', 'segment_id' => $a['segment']]],
                ["/app/campaigns/{$b['campaign']}/transition", ['to' => 'archived']], ["/app/campaigns/{$b['campaign']}/dry-run", []],
                ["/app/campaigns/{$b['campaign']}/dispatches", ['channel' => 'email']], ["/app/automations/{$b['automation']}/state", ['state' => 'archived']],
                ["/app/automations/{$b['automation']}/dry-run", []], ["/app/forms/{$b['form']}/active", ['active' => 0]]] as [$url, $body]) {
                $status = $as()->post($url, $body)->status();
                $this->assertContains($status, [403, 404], "POST {$url} answered {$status}");
            }
            // Choosing the other tenant at login is refused too.
            $this->actingAs($user, 'web')->post('/app/tenants', ['tenant' => $b['tenant']->id])->assertForbidden();
        }

        $this->inTenant($b['tenant'], function () use ($b) {
            $this->assertSame('ABC Tekstil Test kampanyası', DB::table('campaigns')->where('id', $b['campaign'])->value('name'));
            $this->assertSame('draft', DB::table('campaigns')->where('id', $b['campaign'])->value('status'));
            $this->assertTrue((bool) DB::table('lead_forms')->where('id', $b['form'])->value('active'));
            $this->assertSame(0, DB::table('campaign_dispatches')->count());
        });
    }

    public function test_row_level_security_hides_the_other_tenant_even_without_application_scopes(): void
    {
        $a = $this->t['igs-test'];
        $b = $this->t['abc-tekstil-test'];
        $this->inTenant($a['tenant'], function () use ($b) {
            foreach (['campaigns' => $b['campaign'], 'segments' => $b['segment'], 'automations' => $b['automation'], 'lead_forms' => $b['form']] as $table => $id) {
                $this->assertSame(0, DB::table($table)->where('id', $id)->count(), "{$table} row of another tenant visible");
                $this->assertSame(0, DB::table($table)->where('tenant_id', $b['tenant']->id)->count());
            }
            $this->assertSame(0, DB::table('lead_submissions')->where('tenant_id', $b['tenant']->id)->count());
            $this->assertSame(0, DB::table('tenant_users')->where('tenant_id', $b['tenant']->id)->count());
            $this->assertSame(0, DB::table('user_invitations')->where('tenant_id', $b['tenant']->id)->count());
            // Writes into the other tenant fail closed.
            $this->assertSame(0, DB::table('campaigns')->where('id', $b['campaign'])->update(['name' => 'x']));
        });
        $this->assertSame(0, DB::table('campaigns')->count(), 'No context: no rows.');
    }

    public function test_tenant_users_cannot_reach_platform_management(): void
    {
        $host = 'http://'.config('platform.admin_domain');
        $a = $this->t['igs-test'];
        $this->as($a['owner'], $a['tenant'])->get("{$host}/tenants")->assertRedirect(route('admin.login'));
        $this->as($a['owner'], $a['tenant'])->get("{$host}/tenants/{$this->t['abc-tekstil-test']['tenant']->id}")->assertRedirect(route('admin.login'));
        $this->get('http://localhost/tenants')->assertNotFound();
        $this->as($a['owner'], $a['tenant'])->post("{$host}/tenants", ['name' => 'X', 'slug' => 'x-co', 'owner_name' => 'X', 'owner_email' => 'x@x.test'])->assertRedirect(route('admin.login'));
        $this->assertSame(0, DB::connection('pgsql_platform')->table('tenants')->where('slug', 'x-co')->count());
    }
}
