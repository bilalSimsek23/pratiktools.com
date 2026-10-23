<?php

namespace Tests\Feature\Security;

use App\Integrations\Contracts\IntegrationVerifier;
use App\Integrations\ProviderRegistry;
use App\Integrations\VerificationResult;
use App\Models\TenantIntegration;
use App\Security\SensitiveValue;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

#[Group('security')]
final class SecretRedactionTest extends TestCase
{
    use InteractsWithTenants;

    private const SECRET = 'xoxb-REDACTION-CANARY-7f3a9c';

    protected function setUp(): void
    {
        parent::setUp();
        File::delete(glob(storage_path('logs/testing*.log')) ?: []);
    }

    public function test_api_responses_never_contain_the_secret_or_its_envelope(): void
    {
        [$a, $owner] = $this->createTenant('alpha');
        $token = $this->tokenFor($owner, $a);

        $created = $this->api('POST', '/api/v1/integrations', $token, ['provider' => 'klaviyo', 'capability' => 'email', 'secret' => self::SECRET])->assertCreated();
        $id = $created->json('data.id');
        $rotated = $this->api('PUT', "/api/v1/integrations/{$id}/secret", $token, ['secret' => self::SECRET.'-v2'])->assertOk();
        $shown = $this->api('GET', "/api/v1/integrations/{$id}", $token)->assertOk();
        $listed = $this->api('GET', '/api/v1/integrations', $token)->assertOk();
        $audit = $this->api('GET', '/api/v1/audit-logs', $token)->assertOk();

        foreach ([$created, $rotated, $shown, $listed, $audit] as $response) {
            $body = $response->getContent();
            $this->assertStringNotContainsString('REDACTION-CANARY', $body);
            $this->assertStringNotContainsString('encrypted_secret', $body);
            $this->assertStringNotContainsString('"c":', $body);
        }

        $this->assertSame(['id', 'provider', 'capability', 'status', 'has_secret', 'secret_fingerprint', 'scopes', 'settings', 'last_verified_at', 'last_error', 'updated_at'], array_keys($shown->json('data')));
    }

    public function test_logs_never_contain_secrets_or_personal_data(): void
    {
        [$a, $owner] = $this->createTenant('alpha');
        $token = $this->tokenFor($owner, $a);

        app(ProviderRegistry::class)->registerVerifier('klaviyo', new class implements IntegrationVerifier
        {
            public function verify(TenantIntegration $integration, SensitiveValue $secret): VerificationResult
            {
                // A careless connector that logs everything it has.
                Log::error('verification failed for owner@alpha.test +90 532 111 22 33', [
                    'secret' => $secret->reveal(),
                    'wrapped' => $secret,
                    'headers' => ['Authorization' => 'Bearer '.$secret->reveal()],
                    'api_key' => $secret->reveal(),
                ]);

                return VerificationResult::failed('upstream rejected credentials');
            }
        });

        $id = $this->api('POST', '/api/v1/integrations', $token, ['provider' => 'klaviyo', 'capability' => 'email', 'secret' => self::SECRET])->json('data.id');
        $this->api('POST', "/api/v1/integrations/{$id}/verify", $token)->assertAccepted();
        $this->api('POST', '/api/v1/integrations', $token, ['provider' => 'klaviyo', 'capability' => 'email', 'secret' => self::SECRET])->assertUnprocessable();
        Log::info('request context', ['payload' => ['password' => self::SECRET, 'email' => 'customer@example.com']]);

        $logs = collect(glob(storage_path('logs/testing*.log')))->map(fn ($f) => file_get_contents($f))->implode("\n");

        $this->assertNotSame('', $logs);
        $this->assertStringNotContainsString('REDACTION-CANARY', $logs);
        $this->assertStringNotContainsString('owner@alpha.test', $logs);
        $this->assertStringNotContainsString('customer@example.com', $logs);
        $this->assertStringNotContainsString('532 111 22 33', $logs);
        $this->assertStringContainsString('[redacted]', $logs);

        $line = json_decode(collect(explode("\n", trim($logs)))->first(fn ($l) => str_contains($l, 'verification failed')), true);
        $this->assertSame($a->id, $line['extra']['tenant_id'] ?? null, 'Structured log must carry tenant_id.');
        $this->assertArrayHasKey('request_id', $line['extra']);
    }
}
