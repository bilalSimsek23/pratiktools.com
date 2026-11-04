<?php

namespace Tests\Feature\Platform;

use App\Models\PlatformAdmin;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Super Admin activation links (platform:admin:invite): single use, only
 * the token hash stored, one 410 answer for every unusable link, password
 * chosen in the browser, two-factor setup before any Super Admin page, and
 * the platform role can set a password only through the definer function.
 */
final class PlatformAdminActivationTest extends TestCase
{
    private const PASSWORD = 'Benim-cok-guclu-sifrem-2026';

    private string $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = 'http://'.config('platform.admin_domain');
    }

    /**
     * @return array{0: string, 1: string, 2: string} url, token, command output
     */
    private function invite(string $email = 'ilk@pratiktools.test', array $options = []): array
    {
        $this->assertSame(0, Artisan::call('platform:admin:invite', ['email' => $email, 'name' => 'İlk Admin'] + $options));
        $output = Artisan::output();
        $this->assertSame(1, preg_match('#(https?://\S+/activate/([A-Za-z0-9]{64})\?expires=\d+&signature=[0-9a-f]{64})#', $output, $m), $output);

        return [$m[1], $m[2], $output];
    }

    private function activate(string $url, string $password = self::PASSWORD, ?string $confirmation = null): TestResponse
    {
        return $this->post($url, ['password' => $password, 'password_confirmation' => $confirmation ?? $password]);
    }

    private function admin(string $email = 'ilk@pratiktools.test'): PlatformAdmin
    {
        return PlatformAdmin::query()->where('email', $email)->firstOrFail();
    }

    public function test_invite_creates_an_invited_admin_and_stores_only_the_token_hash(): void
    {
        [$url, $token, $output] = $this->invite('Ilk@PratikTools.test');

        $this->assertStringStartsWith($this->host.'/activate/', $url);
        $this->assertSame(1, substr_count($output, $token), 'The link is printed once.');
        $admin = $this->admin();
        $this->assertSame('invited', $admin->status);
        $this->assertSame(hash('sha256', $token), $admin->activation_token_hash);
        $this->assertTrue($admin->activation_expires_at->between(now()->addMinutes(29), now()->addMinutes(31)), 'Default TTL is 30 minutes.');
        $this->assertNull($admin->two_factor_secret);

        $row = (array) DB::connection('pgsql_owner')->table('platform_admins')->where('id', $admin->id)->first();
        $this->assertStringNotContainsString($token, (string) json_encode($row), 'The plain token is never stored.');
        $audit = DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'platform.admin.invited')->sole(['metadata', 'actor_type']);
        $this->assertSame(['reissued' => false, 'expires_in_minutes' => 30], json_decode((string) $audit->metadata, true));
        $this->assertSame('console', $audit->actor_type);
    }

    public function test_an_invited_admin_cannot_sign_in_or_open_any_super_admin_page(): void
    {
        $this->invite();
        // Even with a known password an invited account is refused.
        DB::connection('pgsql_owner')->table('platform_admins')->where('email', 'ilk@pratiktools.test')->update(['password' => Hash::make(self::PASSWORD)]);

        $this->post("{$this->host}/login", ['email' => 'ilk@pratiktools.test', 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertNull(auth('platform')->user());
        foreach (['/', '/tenants', '/tenants/new', '/system'] as $page) {
            $this->get($this->host.$page)->assertRedirect(route('admin.login'));
        }
    }

    public function test_bad_signature_expired_reused_and_replaced_links_all_answer_410(): void
    {
        // Many requests from one address here; the limit itself is tested separately.
        $this->withoutMiddleware(ThrottleRequests::class);
        [$first, $firstToken] = $this->invite();
        $tampered = substr($first, 0, -1).(str_ends_with($first, 'a') ? 'b' : 'a');
        $unsigned = strtok($first, '?');
        $sameAnswer = function (string $url): void {
            $this->get($url)->assertStatus(410)->assertSee('Bağlantı kullanılamıyor')->assertHeader('Cache-Control', 'no-store, private');
            $this->activate($url)->assertStatus(410);
        };
        $sameAnswer($tampered);
        $sameAnswer((string) $unsigned);
        $sameAnswer("{$this->host}/activate/short-token");
        $sameAnswer(str_replace($firstToken, str_repeat('a', 64), $first));

        // Replaced: a new link for the still invited account voids the first one.
        [$second, , $output] = $this->invite();
        $this->assertStringContainsString('previous link no longer works', $output);
        $this->assertSame(1, PlatformAdmin::query()->count());
        $sameAnswer($first);
        $this->get($second)->assertOk();

        // Expired: the signature (application clock) and the stored expiry (database clock).
        $this->travel(31)->minutes();
        $sameAnswer($second);
        $this->travelBack();
        [$third] = $this->invite();
        DB::connection('pgsql_owner')->table('platform_admins')->where('email', 'ilk@pratiktools.test')->update(['activation_expires_at' => now()->subMinute()]);
        $sameAnswer($third);
        $this->assertSame('invited', $this->admin()->status);

        // Reused: works once.
        [$fourth] = $this->invite();
        $this->activate($fourth)->assertRedirect(route('admin.two-factor.setup'));
        auth('platform')->logout();
        $sameAnswer($fourth);
    }

    public function test_activation_sets_the_password_and_requires_two_factor_before_any_page(): void
    {
        [$url] = $this->invite();
        $this->get($url)->assertOk()->assertSee('ilk@pratiktools.test')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');

        $this->activate($url)->assertRedirect(route('admin.two-factor.setup'));
        $admin = $this->admin();
        $this->assertSame('active', $admin->status);
        $this->assertNull($admin->activation_token_hash);
        $this->assertNull($admin->activation_expires_at);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $this->assertSame($admin->id, auth('platform')->id());

        foreach (['/', '/tenants', '/tenants/new', '/system'] as $page) {
            $this->get($this->host.$page)->assertRedirect(route('admin.two-factor.setup'));
        }
        $secret = $this->get("{$this->host}/two-factor/setup")->assertOk()->viewData('secret');
        $this->post("{$this->host}/two-factor/setup", ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect(route('admin.dashboard'));
        $this->get("{$this->host}/tenants")->assertOk();

        // A normal sign-in afterwards: password, then the second factor.
        $this->post("{$this->host}/logout");
        $this->post("{$this->host}/login", ['email' => 'ilk@pratiktools.test', 'password' => self::PASSWORD])->assertRedirect(route('admin.two-factor.challenge'));
        $this->get("{$this->host}/tenants")->assertRedirect(route('admin.two-factor.challenge'));
    }

    public function test_password_rules_and_nothing_sensitive_is_flashed(): void
    {
        [$url] = $this->invite();
        $this->get($url);

        $this->activate($url, 'kisa-sifre-15ch')->assertRedirect()->assertSessionHasErrors('password');
        $this->activate($url, self::PASSWORD, self::PASSWORD.'x')->assertSessionHasErrors('password');
        $this->activate($url, '')->assertSessionHasErrors('password');
        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('password_confirmation'));
        $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode(session()->all()));
        $this->assertSame('invited', $this->admin()->status, 'Nothing changed.');

        $this->activate($url)->assertRedirect(route('admin.two-factor.setup'));
        $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode(session()->all()));
    }

    public function test_tokens_hashes_and_passwords_never_reach_audit_or_logs(): void
    {
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logs) {
            $logs[] = $e->message.' '.json_encode($e->context);
        });

        [$first, $firstToken] = $this->invite();
        [$url, $token] = $this->invite();
        $this->activate($first)->assertStatus(410);
        $this->activate($url, 'kisa')->assertSessionHasErrors('password');
        $this->activate($url)->assertRedirect(route('admin.two-factor.setup'));

        $audit = DB::connection('pgsql_platform')->table('audit_logs')->orderBy('created_at')->get(['action', 'metadata', 'resource_id']);
        $this->assertSame(['platform.admin.invited', 'platform.admin.invited', 'platform.admin.activated', 'platform.login'], $audit->pluck('action')->all());
        $this->assertSame(['via' => 'activation'], json_decode((string) $audit->last()->metadata, true));
        $everything = $audit->toJson().implode("\n", $logs);
        foreach ([$firstToken, $token, hash('sha256', $firstToken), hash('sha256', $token), self::PASSWORD, (string) $this->admin()->password] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $everything);
        }
    }

    public function test_the_platform_role_sets_a_password_only_through_the_activation_function(): void
    {
        [, $token] = $this->invite();
        $platform = DB::connection('pgsql_platform');
        foreach (['password' => Hash::make('x'), 'status' => 'active', 'activation_token_hash' => null, 'activation_expires_at' => now()->addDay()] as $column => $value) {
            try {
                $platform->table('platform_admins')->where('email', 'ilk@pratiktools.test')->update([$column => $value]);
                $this->fail("Platform role updated {$column}.");
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0], 'permission denied');
            }
        }

        // The function refuses a plain password, a wrong token and an active account.
        $call = fn (string $hash, string $password) => $platform->selectOne('SELECT app_platform_admin_activate(?, ?) AS id', [$hash, $password])->id;
        $this->assertNull($call(hash('sha256', $token), 'plain-text-password-1234'));
        $this->assertNull($call(hash('sha256', str_repeat('b', 64)), Hash::make(self::PASSWORD)));
        $this->assertSame('invited', $this->admin()->status);
        $this->assertSame($this->admin()->id, $call(hash('sha256', $token), Hash::make(self::PASSWORD)));
        $this->assertNull($call(hash('sha256', $token), Hash::make(self::PASSWORD)), 'Single use.');

        $owner = DB::connection('pgsql_owner');
        $fn = $owner->selectOne("SELECT pg_get_userbyid(p.proowner) AS owner, p.prosecdef, p.proconfig::text AS config,
            has_function_privilege('public', p.oid, 'EXECUTE') AS public_exec, has_function_privilege(?, p.oid, 'EXECUTE') AS app_exec,
            has_function_privilege(?, p.oid, 'EXECUTE') AS platform_exec FROM pg_proc p WHERE p.proname = 'app_platform_admin_activate'",
            [config('database.roles.app'), config('database.roles.platform')]);
        $this->assertSame(config('database.roles.owner'), $fn->owner);
        $this->assertTrue($fn->prosecdef);
        $this->assertSame('{"search_path=pg_catalog, public"}', $fn->config);
        $this->assertFalse($fn->public_exec);
        $this->assertFalse($fn->app_exec);
        $this->assertTrue($fn->platform_exec);
        $this->assertFalse((bool) $owner->selectOne('SELECT has_table_privilege(?, ?, ?) AS p', [config('database.roles.app'), 'platform_admins', 'SELECT'])->p);

        $this->assertSame(0, Artisan::call('db:verify-roles'), Artisan::output());
    }

    public function test_active_or_disabled_admins_are_refused_and_input_is_validated(): void
    {
        $admin = new PlatformAdmin(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'a-very-long-admin-password', 'status' => 'active']);
        $admin->setConnection('pgsql_owner')->save();
        $disabled = new PlatformAdmin(['name' => 'Eski', 'email' => 'eski@platform.test', 'password' => 'a-very-long-admin-password', 'status' => 'disabled']);
        $disabled->setConnection('pgsql_owner')->save();

        foreach ([['email' => 'ROOT@platform.test'], ['email' => 'eski@platform.test'], ['email' => 'gecersiz'], ['name' => '  '],
            ['--ttl' => '4'], ['--ttl' => '121'], ['--ttl' => 'abc']] as $input) {
            $this->assertSame(1, Artisan::call('platform:admin:invite', $input + ['email' => 'yeni@platform.test', 'name' => 'Yeni']), json_encode($input));
        }
        $this->assertNull(DB::connection('pgsql_owner')->table('platform_admins')->where('email', 'root@platform.test')->value('activation_token_hash'));
        $this->assertSame(2, PlatformAdmin::query()->count());
        $this->assertSame(0, DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'platform.admin.invited')->count());

        [$url] = $this->invite('kisa@platform.test', ['--ttl' => '5']);
        $this->assertTrue($this->admin('kisa@platform.test')->activation_expires_at->lessThanOrEqualTo(now()->addMinutes(5)));
        $this->get($url)->assertOk();
    }

    public function test_the_interactive_create_command_is_audited_without_the_password(): void
    {
        $this->artisan('platform:admin:create', ['email' => 'konsol@platform.test', 'name' => 'Konsol'])
            ->expectsQuestion('Password (min 16 characters)', self::PASSWORD)->assertSuccessful();

        $admin = $this->admin('konsol@platform.test');
        $this->assertSame('active', $admin->status);
        $audit = DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'platform.admin.created')->sole(['metadata', 'resource_id']);
        $this->assertSame($admin->id, $audit->resource_id);
        $this->assertStringNotContainsString(self::PASSWORD, (string) $audit->metadata);
    }

    public function test_activation_links_are_rate_limited_per_address(): void
    {
        [$url] = $this->invite();
        for ($i = 0; $i < 10; $i++) {
            $this->get("{$this->host}/activate/".str_repeat('x', 64))->assertStatus(410);
        }
        $this->get($url)->assertStatus(429);
        $this->activate($url)->assertStatus(429);
        $this->assertSame('invited', $this->admin()->status);
    }
}
