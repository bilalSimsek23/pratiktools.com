<?php

namespace Tests\Feature\Platform;

use App\Audit\Actor;
use App\Connectors\Ses\SesClientFactory;
use App\Email\Data\EmailDraft;
use App\Email\EmailDelivery;
use App\Email\EmailMessageType;
use App\Email\EmailSendBlocked;
use App\Email\EmailSendingMode;
use App\Email\PlatformEmailMode;
use App\Http\Middleware\EnsurePlatformTwoFactor;
use App\Http\Middleware\PlatformRecentAuth;
use App\Jobs\SendEmail;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\InteractsWithSes;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Super Admin › Sistem Durumu › E-posta Gönderim Modu: the platform switch
 * lives in platform_settings, EMAIL_SENDING_MODE is its upper bound, the
 * effective mode is always the strictest of bound, switch and tenant, an
 * unreadable switch counts as off, and live needs the password, a second
 * factor code and the typed phrase in the same request.
 */
final class PlatformEmailModeTest extends TestCase
{
    use InteractsWithSes, InteractsWithTenants;

    private const ADMIN_PASSWORD = 'a-very-long-admin-password';

    private const QA = 'qa@example.com';

    private const CUSTOMER = 'musteri@example.net';

    private string $host;

    private PlatformAdmin $admin;

    private string $otpSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSes();
        $this->host = 'http://'.config('platform.admin_domain');
        config(['site.owner_tenant' => null]);
        $admin = new PlatformAdmin(['name' => 'Root', 'email' => 'root@platform.test', 'password' => self::ADMIN_PASSWORD, 'status' => 'active']);
        $admin->setConnection('pgsql_owner')->save();
        $this->otpSecret = app(Google2FA::class)->generateSecretKey(32);
        $this->admin = PlatformAdmin::query()->findOrFail($admin->id);
        $this->admin->forceFill(['two_factor_secret' => $this->otpSecret, 'two_factor_confirmed_at' => now()])->save();
        // Production starting point: the switch is off.
        $this->switch('off');
    }

    private function switch(string $mode): void
    {
        DB::connection('pgsql_owner')->table('platform_settings')->where('key', 'email.sending_mode')->update(['value' => $mode]);
    }

    private function stored(): string
    {
        return (string) DB::connection('pgsql_owner')->table('platform_settings')->where('key', 'email.sending_mode')->value('value');
    }

    private function asAdmin(bool $recent = true): self
    {
        return $this->actingAs($this->admin, 'platform')->withSession([EnsurePlatformTwoFactor::SESSION_KEY => $this->admin->id, PlatformRecentAuth::SESSION_KEY => $recent ? time() : 0]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function change(string $mode, array $extra = [], bool $recent = true): TestResponse
    {
        $this->app['cache']->store()->flush();

        return $this->asAdmin($recent)->from("{$this->host}/system")->post("{$this->host}/system/email-sending-mode", ['mode' => $mode] + $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function liveInput(array $override = []): array
    {
        return $override + ['acknowledge' => '1', 'phrase' => PlatformEmailMode::LIVE_PHRASE, 'password' => self::ADMIN_PASSWORD,
            'code' => app(Google2FA::class)->getCurrentOtp($this->otpSecret)];
    }

    /**
     * @return Collection<int, object>
     */
    private function audits(string $action): Collection
    {
        return DB::connection('pgsql_platform')->table('audit_logs')->where('action', $action)->orderBy('created_at')->get(['actor_type', 'actor_id', 'metadata', 'created_at']);
    }

    /**
     * A connected tenant with a verified sender and an allowlisted tester.
     *
     * @return array{0: Tenant, 1: TenantIntegration, 2: string}
     */
    private function shop(string $tenantMode): array
    {
        [$tenant] = $this->createTenant('igs');
        $integration = $this->connectSes($tenant);
        $sender = $this->senderIdentity($tenant, $integration);
        $this->emailTestRecipient($tenant, self::QA);
        $this->inTenant($tenant, fn () => DB::table('tenant_settings')->update(['sending_mode' => $tenantMode]));

        return [$tenant, $integration, $sender];
    }

    /**
     * Queues and sends one message; returns [status, block_reason].
     *
     * @return array{0: string, 1: ?string}
     */
    private function deliver(Tenant $tenant, TenantIntegration $integration, string $sender, string $to, EmailMessageType $type = EmailMessageType::Test): array
    {
        return $this->inTenant($tenant, function () use ($integration, $sender, $to, $type) {
            $id = app(EmailDelivery::class)->queue(new EmailDraft($integration->id, $sender, $type, $to, 'Test', 'Metin'), Actor::system());
            app(EmailDelivery::class)->send($id, Actor::system());
            $row = DB::table('email_messages')->where('id', $id)->first(['status', 'block_reason']);

            return [(string) $row->status, $row->block_reason];
        });
    }

    public function test_the_effective_platform_mode_is_the_stricter_of_the_bound_and_the_switch(): void
    {
        foreach ([['off', 'live', 'off'], ['test_only', 'live', 'test_only'], ['live', 'test_only', 'test_only'], ['live', 'off', 'off'], ['live', 'live', 'live'], ['bogus', 'live', 'off']] as [$env, $switch, $expected]) {
            config(['email.sending_mode' => $env]);
            $this->switch($switch);
            $this->assertSame($expected, EmailSendingMode::platform()->value, "env {$env} + switch {$switch}");
        }
    }

    public function test_an_unreadable_switch_counts_as_off(): void
    {
        config(['email.sending_mode' => 'live']);
        $this->switch('live');
        $owner = DB::connection('pgsql_owner');
        $owner->statement('ALTER TABLE platform_settings RENAME TO platform_settings_moved');
        try {
            $this->assertSame(EmailSendingMode::Off, EmailSendingMode::platform());
        } finally {
            $owner->statement('ALTER TABLE platform_settings_moved RENAME TO platform_settings');
        }
        $owner->table('platform_settings')->delete();
        $this->assertSame(EmailSendingMode::Off, EmailSendingMode::platform(), 'A missing row is off too.');
        $owner->table('platform_settings')->insert(['key' => 'email.sending_mode', 'value' => 'live']);
    }

    public function test_off_to_test_only_and_back_from_the_panel_with_audit(): void
    {
        config(['email.sending_mode' => 'test_only']);
        [$igs] = $this->shop('off');

        $page = $this->asAdmin()->get("{$this->host}/system")->assertOk()->assertSee('E-posta Gönderim Modu')->assertSee('🔴 Kapalı')->assertSee('🟡 Yalnızca Test yap');
        $page->assertSee('Engelli: platform gönderim modu kapalı.');

        $this->change('test_only')->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->assertSame(['test_only', EmailSendingMode::TestOnly], [$this->stored(), EmailSendingMode::platform()]);
        $audit = $this->audits('platform.email_sending_mode_changed')->sole();
        $this->assertSame(['platform_admin', $this->admin->id], [$audit->actor_type, $audit->actor_id]);
        $this->assertNotNull($audit->created_at);
        $this->assertEquals(['from' => 'off', 'to' => 'test_only', 'ceiling' => 'test_only', 'effective_before' => 'off', 'effective_after' => 'test_only'], json_decode((string) $audit->metadata, true));
        $this->assertSame($this->admin->id, DB::connection('pgsql_owner')->table('platform_settings')->value('updated_by'));

        // The tenant is still off: Platform TEST_ONLY · IGS OFF · Etkin OFF, with the reason.
        $this->asAdmin()->get("{$this->host}/system")->assertSee('Engelli: müşterinin gönderim modu kapalı.');
        $ses = (string) $this->asAdmin()->get("{$this->host}/tenants/{$igs->id}/integrations/ses")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#Platform modu</dt><dd><code>TEST_ONLY</code>#', $ses);
        $this->assertMatchesRegularExpression('#modu</dt><dd><code>OFF</code>#', $ses);
        $this->assertMatchesRegularExpression('#Etkin mod</dt><dd><code>OFF</code>.*müşterinin gönderim modu kapalı#s', $ses);

        $this->change('off')->assertSessionHasNoErrors();
        $this->assertSame('off', $this->stored());
        $this->assertCount(2, $this->audits('platform.email_sending_mode_changed'));
    }

    public function test_the_switch_never_goes_above_the_environment_bound(): void
    {
        config(['email.sending_mode' => 'off']);
        $this->change('test_only')->assertSessionHasErrors('email_mode');
        $this->assertStringContainsString('Ortam üst sınırı', (string) session('errors')->first('email_mode'));
        $this->assertSame('off', $this->stored());
        $this->assertSame('ABOVE_CEILING', json_decode((string) $this->audits('platform.email_sending_mode_change_refused')->sole()->metadata, true)['reason']);
        $this->asAdmin()->get("{$this->host}/system")->assertSee('Ortam üst sınırı kapalı olduğu için açılamaz.');

        // Even a fully confirmed live request cannot pass a test_only bound.
        config(['email.sending_mode' => 'test_only']);
        $this->change('live', $this->liveInput())->assertSessionHasErrors('email_mode');
        $this->assertSame('off', $this->stored());
        $this->assertSame(EmailSendingMode::Off, EmailSendingMode::platform());
    }

    public function test_live_needs_acknowledgement_phrase_password_and_second_factor_together(): void
    {
        config(['email.sending_mode' => 'live']);
        $this->switch('test_only');
        $this->asAdmin()->get("{$this->host}/system")->assertSee('Canlı moda geç (kritik)')->assertSee(PlatformEmailMode::LIVE_PHRASE);

        $attempts = [
            'LIVE_ACKNOWLEDGE' => ['mode' => 'live'],
            'LIVE_PHRASE' => $this->liveInput(['phrase' => 'canli gonderimi ac']),
            'LIVE_PASSWORD' => $this->liveInput(['password' => 'wrong-password-123456']),
            'LIVE_TWO_FACTOR' => $this->liveInput(['code' => '000000']),
        ];
        foreach ($attempts as $reason => $input) {
            $this->change('live', $input)->assertSessionHasErrors('email_mode');
            $this->assertSame('test_only', $this->stored(), "{$reason} must not switch to live");
        }
        $refused = $this->audits('platform.email_sending_mode_change_refused')->map(fn ($a) => json_decode((string) $a->metadata, true)['reason'])->all();
        $this->assertSame(array_keys($attempts), $refused);

        // All together, in one request: live.
        $this->change('live', $this->liveInput())->assertSessionHasNoErrors();
        $this->assertSame([EmailSendingMode::Live, 'live'], [EmailSendingMode::platform(), $this->stored()]);
        $this->assertSame('live', json_decode((string) $this->audits('platform.email_sending_mode_changed')->sole()->metadata, true)['to']);

        // A replayed second-factor code is refused, and stepping down is one click.
        $this->change('off')->assertSessionHasNoErrors();
        $everything = DB::connection('pgsql_platform')->table('audit_logs')->get(['metadata'])->toJson();
        $this->assertStringNotContainsString(self::ADMIN_PASSWORD, $everything);
        $this->assertStringNotContainsString('wrong-password-123456', $everything);
    }

    public function test_the_service_refuses_live_without_strong_confirmation(): void
    {
        config(['email.sending_mode' => 'live']);
        try {
            app(PlatformEmailMode::class)->set(EmailSendingMode::Live, Actor::platformAdmin($this->admin->id));
            $this->fail('Live without confirmation must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('LIVE_NOT_CONFIRMED', $e->getMessage());
        }
        $this->assertSame('off', $this->stored());
    }

    public function test_changes_need_the_second_factor_a_recent_password_and_are_rate_limited(): void
    {
        config(['email.sending_mode' => 'test_only']);
        $this->post("{$this->host}/system/email-sending-mode", ['mode' => 'test_only'])->assertRedirect(route('admin.login'));
        $this->actingAs($this->admin, 'platform')->post("{$this->host}/system/email-sending-mode", ['mode' => 'test_only'])->assertRedirect(route('admin.two-factor.challenge'));
        $this->change('test_only', recent: false)->assertRedirect(route('admin.confirm'));
        $this->assertSame('off', $this->stored());

        // Refused requests above count too; start the budget fresh.
        $this->app['cache']->store()->flush();
        for ($n = 0; $n < 5; $n++) {
            $this->asAdmin()->post("{$this->host}/system/email-sending-mode", ['mode' => 'bogus'])->assertSessionHasErrors('mode');
        }
        $this->asAdmin()->post("{$this->host}/system/email-sending-mode", ['mode' => 'test_only'])->assertStatus(429);
        $this->assertSame('off', $this->stored());
    }

    public function test_platform_off_blocks_every_tenant_and_takes_effect_for_queued_mail(): void
    {
        config(['email.sending_mode' => 'live']);
        $this->switch('test_only');
        [$igs, $ses, $sender] = $this->shop('internal');
        // Queued, not yet sent (the test queue would otherwise send at once).
        Queue::fake([SendEmail::class]);
        $id = $this->inTenant($igs, fn () => app(EmailDelivery::class)->queue(new EmailDraft($ses->id, $sender, EmailMessageType::Test, self::QA, 'Test', 'Metin'), Actor::system()));

        // Turned off after queueing: the next send attempt is blocked, no request leaves.
        $this->switch('off');
        $this->inTenant($igs, fn () => app(EmailDelivery::class)->send($id, Actor::system()));
        $this->assertSame(['blocked', 'SENDING_MODE_OFF'], $this->inTenant($igs, fn () => [DB::table('email_messages')->where('id', $id)->value('status'), DB::table('email_messages')->where('id', $id)->value('block_reason')]));
        $this->assertSame([], $this->sesRequestsTo('/v2/email/outbound-emails'));
        // Not even the key is decrypted for sending.
        try {
            $this->inTenant($igs, fn () => app(SesClientFactory::class)->make($ses->fresh(), SesClientFactory::SEND_PURPOSE));
            $this->fail('The send client must not be built while the platform switch is off.');
        } catch (EmailSendBlocked $e) {
            $this->assertSame('SENDING_MODE_OFF', $e->reason);
        }
    }

    public function test_test_only_reaches_only_test_recipients_and_the_tenant_mode_still_applies(): void
    {
        config(['email.sending_mode' => 'live']);
        $this->switch('test_only');
        [$igs, $ses, $sender] = $this->shop('live');

        // Platform test_only, tenant live: customers are still unreachable.
        $this->assertSame(['blocked', 'NOT_TEST_RECIPIENT'], $this->deliver($igs, $ses, $sender, self::CUSTOMER, EmailMessageType::Transactional));
        $this->assertSame('sent', $this->deliver($igs, $ses, $sender, self::QA)[0]);
        $this->assertCount(1, $this->sesRequestsTo('/v2/email/outbound-emails'));

        // Platform live, tenant off: that tenant sends nothing.
        $this->switch('live');
        $this->inTenant($igs, fn () => DB::table('tenant_settings')->update(['sending_mode' => 'off']));
        $this->assertSame(['blocked', 'SENDING_MODE_OFF'], $this->deliver($igs, $ses, $sender, self::QA));

        // send_email is still required on its own.
        $this->inTenant($igs, fn () => DB::table('tenant_settings')->update(['sending_mode' => 'internal']));
        $this->inTenant($igs, fn () => $ses->forceFill(['scopes' => ['read_account', 'read_identities']])->save());
        $this->assertSame(['blocked', 'SEND_SCOPE_MISSING'], $this->deliver($igs, $ses, $sender, self::QA));
        $this->assertCount(1, $this->sesRequestsTo('/v2/email/outbound-emails'));
    }

    public function test_only_the_known_switch_with_known_values_can_be_stored(): void
    {
        $platform = DB::connection('pgsql_platform');
        foreach ([fn () => $platform->table('platform_settings')->update(['value' => 'everything']),
            fn () => $platform->table('platform_settings')->insert(['key' => 'email.other', 'value' => 'live']),
            fn () => $platform->table('platform_settings')->delete(),
            fn () => $platform->table('platform_settings')->update(['key' => 'email.renamed']),
            fn () => DB::table('platform_settings')->update(['value' => 'live'])] as $n => $write) {
            try {
                $write();
                $this->fail("Write {$n} must be refused.");
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/permission denied|violates check constraint/', $e->getMessage());
            }
        }
        $this->assertSame('off', $this->stored());
        $this->assertSame(0, Artisan::call('db:verify-roles'), Artisan::output());
    }
}
