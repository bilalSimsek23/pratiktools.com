<?php

namespace Tests\Feature\Infra;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/_proxy-probe', fn (Request $r) => [
            'ip' => $r->ip(),
            'secure' => $r->isSecure(),
            'host' => $r->getHost(),
            'url' => url('/x'),
            'rate_key' => RateLimiter::limiter('webhooks')($r)->key,
        ]);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function probe(array $headers, string $remote = '10.1.2.3'): array
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remote])->withHeaders($headers)->getJson('/_proxy-probe')->assertOk()->json();
    }

    private const EDGE = [
        // The client sent a forged first entry; the edge appended the real one.
        'X-Forwarded-For' => '6.6.6.6, 203.0.113.9',
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Port' => '443',
        'X-Forwarded-Host' => 'evil.example',
    ];

    public function test_behind_the_cloud_edge_https_and_the_real_client_ip_are_used(): void
    {
        config(['platform.trusted_proxies' => 'REMOTE_ADDR']);

        $seen = $this->probe(self::EDGE);

        $this->assertSame('203.0.113.9', $seen['ip'], 'Right-most entry, appended by the trusted proxy.');
        $this->assertTrue($seen['secure']);
        $this->assertStringStartsWith('https://', $seen['url']);
        $this->assertNotSame('evil.example', $seen['host'], 'X-Forwarded-Host is never trusted.');
    }

    public function test_a_wildcard_never_means_trust_everyone(): void
    {
        config(['platform.trusted_proxies' => '*']);

        $this->assertSame('203.0.113.9', $this->probe(self::EDGE)['ip'], 'Spoofed left-most value must not win.');
    }

    public function test_requests_not_from_a_trusted_proxy_are_taken_literally(): void
    {
        config(['platform.trusted_proxies' => '172.16.0.0/12']);
        $seen = $this->probe(self::EDGE, '198.51.100.7');

        $this->assertSame('198.51.100.7', $seen['ip']);
        $this->assertFalse($seen['secure']);

        config(['platform.trusted_proxies' => 'none']);
        $this->assertSame('10.1.2.3', $this->probe(self::EDGE)['ip']);
    }

    public function test_outside_production_nothing_is_trusted_by_default(): void
    {
        config(['platform.trusted_proxies' => null]);

        $seen = $this->probe(self::EDGE);
        $this->assertSame('10.1.2.3', $seen['ip']);
        $this->assertFalse($seen['secure']);
    }

    public function test_rate_limits_key_on_the_real_client_ip(): void
    {
        config(['platform.trusted_proxies' => 'REMOTE_ADDR']);

        // Rotating the forged entry must not give the client fresh buckets.
        $keys = array_map(fn ($forged) => $this->probe(['X-Forwarded-For' => "{$forged}, 203.0.113.9"] + self::EDGE)['rate_key'], ['1.1.1.1', '2.2.2.2', '3.3.3.3']);

        $this->assertSame(['webhook:203.0.113.9'], array_values(array_unique($keys)));
    }
}
