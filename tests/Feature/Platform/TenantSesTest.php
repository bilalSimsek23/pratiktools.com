<?php

namespace Tests\Feature\Platform;

use App\Http\Middleware\EnsurePlatformTwoFactor;
use App\Http\Middleware\PlatformRecentAuth;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Security\Vault\SecretVault;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\InteractsWithSes;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Super Admin › Müşteriler › Entegrasyonlar › Amazon SES: the IAM key pair
 * is write-only (vault, never shown, flashed, logged or audited), given as
 * a pair or not at all, every change needs a recent password and the second
 * factor and is rate limited, everything runs inside the tenant's own
 * context, and the connection test is the existing read-only account check.
 */
final class TenantSesTest extends TestCase
{
    use InteractsWithSes, InteractsWithTenants;

    private const NEW_KEY = 'ROTATEDTESTKEY000099';

    private const NEW_SECRET = 'rotated/ses+secret-never-real-9876543210';

    private string $host;

    private PlatformAdmin $admin;

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSes();
        $this->host = 'http://'.config('platform.admin_domain');
        config(['app.url' => 'https://pratiktools.test', 'site.owner_tenant' => null]);
        $admin = new PlatformAdmin(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'a-very-long-admin-password', 'status' => 'active']);
        $admin->setConnection('pgsql_owner')->save();
        $this->admin = PlatformAdmin::query()->findOrFail($admin->id);
        $this->admin->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(32), 'two_factor_confirmed_at' => now()])->save();
        $this->app['events']->listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = $e->message.' '.json_encode($e->context);
        });
    }

    private function asAdmin(bool $recent = true, bool $twoFactor = true): self
    {
        return $this->actingAs($this->admin, 'platform')->withSession(array_filter([EnsurePlatformTwoFactor::SESSION_KEY => $twoFactor ? $this->admin->id : null,
            PlatformRecentAuth::SESSION_KEY => $recent ? time() : 0], fn ($v) => $v !== null));
    }

    private function page(Tenant $tenant): string
    {
        return "{$this->host}/tenants/{$tenant->id}/integrations/ses";
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function save(Tenant $tenant, array $input, bool $recent = true): TestResponse
    {
        return $this->asAdmin($recent)->from($this->page($tenant))->post($this->page($tenant), $input + [
            'region' => self::SES_REGION, 'configuration_set' => 'pratiktools-events', 'sns_topic_arns' => self::SNS_TOPIC,
        ]);
    }

    private function verify(Tenant $tenant): TestResponse
    {
        return $this->asAdmin()->from($this->page($tenant))->post("{$this->page($tenant)}/test");
    }

    private function integration(Tenant $tenant): ?object
    {
        return $this->inTenant($tenant, fn () => DB::table('tenant_integrations')->where('provider', 'amazon_ses')->first());
    }

    private function reveal(Tenant $tenant): string
    {
        return $this->inTenant($tenant, fn () => app(SecretVault::class)->reveal(TenantIntegration::query()->where('provider', 'amazon_ses')->firstOrFail(), 'test')->reveal());
    }

    /**
     * @return Collection<int, object>
     */
    private function audits(string $action): Collection
    {
        return DB::connection('pgsql_platform')->table('audit_logs')->where('action', $action)->orderBy('created_at')->get(['tenant_id', 'actor_type', 'actor_id', 'resource_id', 'metadata']);
    }

    /**
     * The production account the panel should report: 50k/day, 14/s, out of the sandbox.
     */
    private function productionAccount(): void
    {
        $this->sesOverride = fn (Request $r) => match ((string) parse_url($r->url(), PHP_URL_PATH)) {
            '/v2/email/account' => str_contains((string) ($r->header('Authorization')[0] ?? ''), self::SES_ACCESS_KEY) ? Http::response([
                'ProductionAccessEnabled' => true, 'SendingEnabled' => true, 'EnforcementStatus' => 'HEALTHY',
                'SendQuota' => ['Max24HourSend' => 50000.0, 'MaxSendRate' => 14.0, 'SentLast24Hours' => 12.0], 'Details' => ['MailType' => 'MARKETING'],
            ]) : null,
            '/v2/email/configuration-sets/pratiktools-events' => Http::response(['ConfigurationSetName' => 'pratiktools-events', 'SendingOptions' => ['SendingEnabled' => true],
                'TrackingOptions' => ['CustomRedirectDomain' => 'click.news.example.com']]),
            '/v2/email/configuration-sets/pratiktools-events/event-destinations' => Http::response(['EventDestinations' => [[
                'Name' => 'sns-events', 'Enabled' => true, 'MatchingEventTypes' => ['SEND', 'DELIVERY', 'BOUNCE', 'COMPLAINT', 'REJECT', 'OPEN'],
                'SnsDestination' => ['TopicArn' => self::SNS_TOPIC],
            ]]]),
            default => null,
        };
    }

    /**
     * Nowhere but the vault ciphertext: not in the integration row, its
     * errors, the audit trail, the logs, the session or the responses.
     */
    private function assertNowhere(string $secret, TestResponse ...$responses): void
    {
        $everything = DB::connection('pgsql_platform')->table('audit_logs')->get(['action', 'resource_type', 'resource_id', 'actor_id', 'metadata'])->toJson()
            .implode("\n", $this->logs).json_encode(session()->all());
        foreach (Tenant::query()->get() as $tenant) {
            $everything .= $this->inTenant($tenant, fn () => DB::table('tenant_integrations')->get()->toJson().DB::table('integration_errors')->get()->toJson());
        }
        foreach ($responses as $r) {
            $everything .= $r->getContent().json_encode($r->headers->all());
        }
        $this->assertStringNotContainsString($secret, $everything);
    }

    public function test_the_first_save_stores_the_key_pair_in_the_vault_only(): void
    {
        [$igs] = $this->createTenant('igs');
        $this->asAdmin()->get("{$this->host}/tenants/{$igs->id}?tab=entegrasyonlar")->assertOk()->assertSee($this->page($igs), false)->assertSee('E-posta / SES Hazırlık');
        $this->asAdmin()->get($this->page($igs))->assertOk()->assertSee('Yapılandırılmadı');

        $saved = $this->save($igs, ['aws_access_key_id' => self::SES_ACCESS_KEY, 'aws_secret_access_key' => self::SES_SECRET, 'tracking' => ['open']])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'SES ayarları ve AWS kimlik bilgileri kaydedildi. Bağlantıyı test edin.');

        $row = $this->integration($igs);
        $this->assertSame(['email', 'pending', null], [$row->capability, $row->status, $row->last_verified_at]);
        $this->assertEquals(['region' => 'eu-central-1', 'configuration_set' => 'pratiktools-events', 'sns_topic_arns' => [self::SNS_TOPIC], 'tracking' => ['open'], 'email_role' => 'primary'],
            json_decode($row->settings, true));
        $this->assertSame(['read_account', 'read_identities'], json_decode($row->scopes, true), 'Scopes start read-only.');
        $this->assertSame(['access_key_id' => self::SES_ACCESS_KEY, 'secret_access_key' => self::SES_SECRET], json_decode($this->reveal($igs), true));

        $created = $this->audits('integration.secret_created')->sole();
        $this->assertSame([$igs->id, 'platform_admin', $this->admin->id, $row->id], [$created->tenant_id, $created->actor_type, $created->actor_id, $created->resource_id]);
        $this->assertSame(['fingerprint' => $row->secret_fingerprint], json_decode($created->metadata, true));

        $page = $this->asAdmin()->get($this->page($igs))->assertOk()->assertSee('AWS kimlik bilgileri kayıtlı')->assertSee('Test bekliyor')->assertSee('Platform yöneticisi (Root)')
            ->assertSee('SES Bağlantısını Test Et');
        $this->assertDoesNotMatchRegularExpression('/name="aws_(access_key_id|secret_access_key)"[^>]*value=/', (string) $page->getContent(), 'The key fields are never filled in.');
        $this->assertStringNotContainsString((string) $row->secret_fingerprint, (string) $page->getContent(), 'Not even the fingerprint is shown.');
        $this->assertNowhere(self::SES_SECRET, $saved, $page);
        $this->assertNowhere(self::SES_ACCESS_KEY, $saved, $page);
    }

    public function test_a_new_pair_replaces_the_old_one_and_needs_a_new_test(): void
    {
        [$igs] = $this->createTenant('igs');
        $this->save($igs, ['aws_access_key_id' => self::SES_ACCESS_KEY, 'aws_secret_access_key' => self::SES_SECRET]);
        $this->verify($igs)->assertSessionHas('status', 'SES bağlantı testi başarılı.');
        $before = $this->integration($igs);
        $this->assertSame('connected', $before->status);
        $this->assertArrayHasKey('account', json_decode($before->settings, true));

        $saved = $this->save($igs, ['aws_access_key_id' => self::NEW_KEY, 'aws_secret_access_key' => self::NEW_SECRET])->assertSessionHasNoErrors();

        $after = $this->integration($igs);
        $this->assertSame(['pending', null, null], [$after->status, $after->last_verified_at, $after->last_error]);
        $this->assertArrayNotHasKey('account', json_decode($after->settings, true), 'The old account check no longer counts.');
        $this->assertNotSame($before->encrypted_secret, $after->encrypted_secret);
        $this->assertSame(self::NEW_KEY, json_decode($this->reveal($igs), true)['access_key_id']);
        $this->assertEquals(['previous_fingerprint' => $before->secret_fingerprint, 'fingerprint' => $after->secret_fingerprint],
            json_decode($this->audits('integration.secret_changed')->sole()->metadata, true));

        // The fake SES only knows the first key: the new one is rejected → İşlem gerekli.
        $this->verify($igs)->assertSessionHasErrors('ses_test');
        $this->assertSame('action_required', $this->integration($igs)->status);
        $this->asAdmin()->get($this->page($igs))->assertSee('İşlem gerekli')->assertSee('UnrecognizedClientException');
        $this->assertNowhere(self::NEW_SECRET, $saved);
        $this->assertNowhere(self::SES_SECRET);
    }

    public function test_an_empty_pair_keeps_the_stored_one_and_half_a_pair_is_refused(): void
    {
        [$igs] = $this->createTenant('igs');
        $this->save($igs, ['aws_access_key_id' => self::SES_ACCESS_KEY, 'aws_secret_access_key' => self::SES_SECRET]);
        $this->verify($igs);
        $before = $this->integration($igs);

        $this->save($igs, ['aws_access_key_id' => '', 'aws_secret_access_key' => '', 'configuration_set' => 'other-set'])->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'SES ayarları kaydedildi.');
        $after = $this->integration($igs);
        $this->assertSame([$before->encrypted_secret, $before->secret_fingerprint, 'connected'], [$after->encrypted_secret, $after->secret_fingerprint, $after->status]);
        $this->assertSame('other-set', json_decode($after->settings, true)['configuration_set']);
        $this->assertEquals(['changed' => ['configuration_set'], 'from' => ['configuration_set' => 'pratiktools-events'], 'to' => ['configuration_set' => 'other-set']],
            json_decode($this->audits('integration.settings_changed')->sole()->metadata, true));

        foreach ([['aws_access_key_id' => self::NEW_KEY, 'aws_secret_access_key' => ''], ['aws_access_key_id' => '', 'aws_secret_access_key' => self::NEW_SECRET]] as $half) {
            $r = $this->save($igs, $half)->assertSessionHasErrors('ses')->assertSessionMissing('_old_input.aws_access_key_id')->assertSessionMissing('_old_input.aws_secret_access_key');
            $this->assertStringContainsString('birlikte girilmelidir', (string) session('errors')->first('ses'));
            $this->assertNowhere(self::NEW_SECRET, $r);
            $this->assertNowhere(self::NEW_KEY, $r);
        }
        $this->assertSame($before->encrypted_secret, $this->integration($igs)->encrypted_secret);
        $this->assertCount(0, $this->audits('integration.secret_changed'));

        // A malformed key, an invalid region or topic: refused, nothing stored, nothing echoed.
        $this->save($igs, ['aws_access_key_id' => 'not-a-key', 'aws_secret_access_key' => self::NEW_SECRET])->assertSessionHasErrors('ses');
        $this->save($igs, ['region' => 'mars-1'])->assertSessionHasErrors('ses');
        $this->save($igs, ['sns_topic_arns' => 'arn:aws:sqs:eu-central-1:1:q'])->assertSessionHasErrors('ses');
        $this->assertNowhere(self::NEW_SECRET);

        // A new integration needs the pair.
        [$other] = $this->createTenant('other');
        $this->save($other, [])->assertSessionHasErrors('ses');
        $this->assertNull($this->integration($other));
    }

    public function test_the_account_check_shows_the_production_values_and_the_configuration_set(): void
    {
        [$igs] = $this->createTenant('igs');
        $this->productionAccount();
        $this->save($igs, ['aws_access_key_id' => self::SES_ACCESS_KEY, 'aws_secret_access_key' => self::SES_SECRET]);
        $this->verify($igs)->assertSessionHas('status', 'SES bağlantı testi başarılı.');

        $settings = json_decode($this->integration($igs)->settings, true);
        $this->assertSame([true, 50000.0, 14.0, 'eu-central-1'], [$settings['account']['production_access'], (float) $settings['account']['max_24h_send'],
            (float) $settings['account']['max_send_rate'], $settings['account']['region']]);
        $this->assertSame(['ok', true, ['OPEN']], [$settings['configuration_set_check']['state'], $settings['configuration_set_check']['bounce_complaint'], $settings['configuration_set_check']['open_click']]);
        $this->assertSame(['GET'], array_values(array_unique(array_map(fn (Request $r) => $r->method(), $this->sesRequests))), 'Read-only: nothing was created or changed at SES.');
        $audit = json_decode($this->audits('email.account_checked')->sole()->metadata, true);
        $this->assertSame(['configuration_set' => 'ok', 'enforcement_status' => 'HEALTHY', 'max_24h_send' => 50000, 'max_send_rate' => 14, 'production_access' => true, 'region' => 'eu-central-1',
            'sending_enabled' => true], collect($audit)->sortKeys()->all());

        $this->asAdmin()->get($this->page($igs))->assertOk()->assertSee('50.000')->assertSee('14 / sn')->assertSee('HEALTHY')->assertSee('SES\'te var')
            ->assertSee('click.news.example.com');
    }

    public function test_iam_gaps_and_ses_errors_are_reported_safely(): void
    {
        [$igs] = $this->createTenant('igs');
        $this->save($igs, ['aws_access_key_id' => self::SES_ACCESS_KEY, 'aws_secret_access_key' => self::SES_SECRET]);

        // The key may read the account but not the configuration set.
        $this->sesOverride = fn (Request $r) => str_contains($r->url(), '/configuration-sets/')
            ? Http::response(['message' => 'User is not authorized'], 403, ['x-amzn-ErrorType' => 'AccessDeniedException']) : null;
        $this->verify($igs)->assertSessionHas('status');
        $this->assertSame('iam_required', json_decode($this->integration($igs)->settings, true)['configuration_set_check']['state']);
        $this->asAdmin()->get($this->page($igs))->assertSee('Doğrulanamadı – IAM yetkisi gerekli');

        // SES itself fails: Hata, with the classified (safe) message.
        $this->sesOverride = fn (Request $r) => Http::response(['message' => 'boom'], 500, ['x-amzn-ErrorType' => 'InternalFailure']);
        $this->verify($igs)->assertSessionHasErrors('ses_test');
        $row = $this->integration($igs);
        $this->assertSame('error', $row->status);
        $this->assertStringContainsString('SES server error', (string) $row->last_error);
        $this->assertNowhere(self::SES_SECRET);
    }

    public function test_changes_need_the_second_factor_and_a_recent_password(): void
    {
        [$igs] = $this->createTenant('igs');
        $input = ['region' => self::SES_REGION, 'aws_access_key_id' => self::SES_ACCESS_KEY, 'aws_secret_access_key' => self::SES_SECRET];

        $this->post($this->page($igs), $input)->assertRedirect();
        $this->asAdmin(twoFactor: false)->get($this->page($igs))->assertRedirect(route('admin.two-factor.challenge'));
        $this->asAdmin(twoFactor: false)->post($this->page($igs), $input)->assertRedirect(route('admin.two-factor.challenge'));
        foreach (['', '/test', '/senders', '/sns/confirm', '/scope', '/test-recipients', '/test-recipients/remove', '/test-email'] as $path) {
            $this->asAdmin(recent: false)->post($this->page($igs).$path, $input)->assertRedirect(route('admin.confirm'));
        }
        $this->assertNull($this->integration($igs));
        $this->assertNowhere(self::SES_SECRET);
    }

    public function test_changes_and_tests_are_rate_limited(): void
    {
        [$igs] = $this->createTenant('igs');
        $this->save($igs, ['aws_access_key_id' => self::SES_ACCESS_KEY, 'aws_secret_access_key' => self::SES_SECRET]);
        for ($n = 0; $n < 6; $n++) {
            $this->verify($igs)->assertRedirect();
        }
        $this->verify($igs)->assertStatus(429);
        for ($n = 0; $n < 3; $n++) {
            $this->asAdmin()->post("{$this->page($igs)}/test-email", [])->assertRedirect();
        }
        $this->asAdmin()->post("{$this->page($igs)}/test-email", [])->assertStatus(429);
    }

    public function test_a_tenant_sees_and_changes_only_its_own_integration(): void
    {
        [$igs] = $this->createTenant('igs');
        [$other] = $this->createTenant('other');
        $mine = $this->connectSes($igs);
        $this->senderIdentity($igs, $mine);
        $this->emailTestRecipient($igs, 'qa-igs@example.com');

        $page = $this->asAdmin()->get($this->page($other))->assertOk()->assertSee('Yapılandırılmadı');
        foreach ([self::FROM, 'qa-igs@example.com', 'pratiktools-events', self::SNS_TOPIC] as $needle) {
            $page->assertDontSee($needle);
        }
        $this->asAdmin()->post("{$this->page($other)}/test")->assertSessionHasErrors('ses_test');
        $this->asAdmin()->post("{$this->page($other)}/test-recipients/remove", ['email' => 'qa-igs@example.com'])->assertSessionHasNoErrors();
        $this->assertTrue($this->inTenant($igs, fn () => (bool) DB::table('test_recipients')->value('active')), 'Another tenant\'s allowlist is untouched.');
        $this->asAdmin()->post("{$this->host}/tenants/00000000-0000-0000-0000-000000000000/integrations/ses/test")->assertNotFound();
        $this->asAdmin()->get("{$this->host}/tenants/not-a-uuid/integrations/ses")->assertNotFound();

        // Outside a tenant context the runtime role sees nothing (RLS fail-closed).
        $this->assertSame(0, DB::table('tenant_integrations')->count());
        $this->assertSame(0, DB::table('email_sender_identities')->count());
        $this->assertSame(0, DB::table('test_recipients')->count());
        // The platform role reads no secret, setting, sender or test recipient.
        foreach (['SELECT encrypted_secret FROM tenant_integrations', 'SELECT settings FROM tenant_integrations', 'SELECT from_email FROM email_sender_identities',
            'SELECT provider_recipient_id FROM test_recipients', 'SELECT payload FROM webhook_inbox'] as $sql) {
            try {
                DB::connection('pgsql_platform')->select($sql);
                $this->fail("The platform role must not run: {$sql}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('permission denied', $e->getMessage());
            }
        }
    }
}
