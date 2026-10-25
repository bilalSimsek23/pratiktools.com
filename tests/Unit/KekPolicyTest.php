<?php

namespace Tests\Unit;

use App\Security\Vault\CloudSecretKeyEncryptionService;
use App\Security\Vault\Exceptions\VaultException;
use App\Security\Vault\KekPolicy;
use App\Security\Vault\SymmetricKeyring;
use PHPUnit\Framework\TestCase;

final class KekPolicyTest extends TestCase
{
    private static function key(): string
    {
        do {
            $raw = random_bytes(32);
        } while (KekPolicy::lowEntropy($raw));

        return base64_encode($raw);
    }

    /**
     * @param  array<string, ?string>  $others
     * @return list<string>
     */
    private function problems(string $key, string $previous = '', array $others = []): array
    {
        return KekPolicy::problems(['key' => $key, 'previous' => $previous], $others);
    }

    public function test_a_random_kek_passes(): void
    {
        $this->assertSame([], $this->problems(self::key(), self::key(), ['APP_KEY' => 'base64:'.self::key(), 'AUDIT_HASH_KEY' => self::key()]));
    }

    public function test_malformed_wrong_length_and_missing_keys_are_refused(): void
    {
        $this->assertSame(['SECRET_VAULT_KEK is not set'], $this->problems(''));
        $this->assertSame(['SECRET_VAULT_KEK must be base64 of exactly 32 bytes'], $this->problems('not base64 at all!!'));
        $this->assertSame(['SECRET_VAULT_KEK must be base64 of exactly 32 bytes'], $this->problems(base64_encode(random_bytes(31))));
        $this->assertSame(['SECRET_VAULT_KEK must be base64 of exactly 32 bytes'], $this->problems(base64_encode(random_bytes(33))));
        $this->assertStringContainsString('SECRET_VAULT_KEK_PREVIOUS is malformed', $this->problems(self::key(), 'abc')[0]);
    }

    public function test_current_equal_to_previous_or_to_other_secrets_is_refused(): void
    {
        $key = self::key();

        $this->assertContains('SECRET_VAULT_KEK must differ from every SECRET_VAULT_KEK_PREVIOUS entry', $this->problems($key, $key));
        $this->assertContains('SECRET_VAULT_KEK must not equal APP_KEY', $this->problems($key, '', ['APP_KEY' => "base64:{$key}"]));
        $this->assertContains('SECRET_VAULT_KEK must not equal AUDIT_HASH_KEY', $this->problems($key, '', ['AUDIT_HASH_KEY' => $key]));
        $this->assertContains('SECRET_VAULT_KEK must not equal SECRET_VAULT_FINGERPRINT_KEY', $this->problems($key, '', ['SECRET_VAULT_FINGERPRINT_KEY' => $key]));
    }

    public function test_known_test_keys_and_low_entropy_keys_are_refused(): void
    {
        $testKek = 'dGVzdGluZy1vbmx5LWtlay1ub3QtYS1zZWNyZXQtMDE=';

        $this->assertContains('SECRET_VAULT_KEK is a known test key from this repository', $this->problems($testKek));
        $this->assertContains('SECRET_VAULT_KEK does not look random (generate it with php artisan vault:generate-kek)', $this->problems(base64_encode(str_repeat("\0", 32))));
        $this->assertContains('SECRET_VAULT_KEK does not look random (generate it with php artisan vault:generate-kek)', $this->problems(base64_encode('correct horse battery staple 123')));
    }

    public function test_too_many_previous_keys_are_refused(): void
    {
        $previous = implode(',', array_map(fn () => self::key(), range(1, CloudSecretKeyEncryptionService::MAX_PREVIOUS + 1)));

        $this->assertStringContainsString('more than 3', $this->problems(self::key(), $previous)[0]);
    }

    public function test_every_key_committed_in_phpunit_xml_is_a_known_test_key(): void
    {
        preg_match_all('/name="([A-Z_]+)" value="(?:base64:)?([A-Za-z0-9+\/]{43}=)"/', (string) file_get_contents(__DIR__.'/../../phpunit.xml'), $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);

        foreach ($m as [, $name, $value]) {
            $this->assertContains(hash('sha256', (string) base64_decode($value, true)), KekPolicy::KNOWN_TEST_KEY_HASHES, "{$name} from phpunit.xml must be listed");
        }
    }

    public function test_every_key_in_the_ci_workflow_is_a_known_test_key(): void
    {
        preg_match_all('/^\s+([A-Z_]+): "(?:base64:)?([A-Za-z0-9+\/]{43}=)"/m', (string) file_get_contents(__DIR__.'/../../.github/workflows/ci.yml'), $m, PREG_SET_ORDER);
        $this->assertCount(4, $m, 'APP_KEY, SECRET_VAULT_LOCAL_KEK, SECRET_VAULT_FINGERPRINT_KEY, AUDIT_HASH_KEY');

        foreach ($m as [, $name, $value]) {
            $this->assertContains(hash('sha256', (string) base64_decode($value, true)), KekPolicy::KNOWN_TEST_KEY_HASHES, "{$name} from ci.yml must be a known test key");
        }
    }

    public function test_kek_ids_are_derived_deterministically_and_reveal_nothing(): void
    {
        $raw = random_bytes(32);
        $id = SymmetricKeyring::deriveId('cs-', $raw);

        $this->assertSame($id, SymmetricKeyring::deriveId('cs-', $raw));
        $this->assertMatchesRegularExpression('/^cs-[0-9a-f]{16}$/', $id);
        $this->assertNotSame($id, SymmetricKeyring::deriveId('cs-', random_bytes(32)));
        $this->assertSame('cs-'.substr(hash_hmac('sha256', 'pratiktools.kek-id.v1', $raw), 0, 16), $id);
        $this->assertStringNotContainsString(bin2hex($raw), $id);
    }

    public function test_keyring_wraps_with_current_and_unwraps_with_previous(): void
    {
        $old = random_bytes(32);
        $new = random_bytes(32);
        $dek = random_bytes(32);

        $before = new SymmetricKeyring('cs-', $old);
        $wrappedOld = $before->wrap($dek, 'tenant:a|dek:v1');

        $after = new SymmetricKeyring('cs-', $new, [$old]);
        $this->assertSame(SymmetricKeyring::deriveId('cs-', $new), $after->currentKeyId());
        $this->assertSame($dek, $after->unwrap($wrappedOld, $before->currentKeyId(), 'tenant:a|dek:v1'));
        $this->assertSame($dek, (new SymmetricKeyring('cs-', $new))->unwrap($after->wrap($dek, 'ctx'), $after->currentKeyId(), 'ctx'), 'New wraps use the current key.');

        $this->expectException(VaultException::class);
        $after->unwrap($wrappedOld, $before->currentKeyId(), 'tenant:b|dek:v1');
    }

    public function test_duplicate_keys_in_a_keyring_are_refused(): void
    {
        $raw = random_bytes(32);

        $this->expectException(VaultException::class);
        new SymmetricKeyring('cs-', $raw, [$raw]);
    }

    public function test_keyrings_never_dump_or_serialize_key_material(): void
    {
        $raw = random_bytes(32);
        $ring = new SymmetricKeyring('cs-', $raw);

        $this->assertStringNotContainsString(bin2hex($raw), bin2hex(print_r($ring, true)));
        $this->assertStringNotContainsString(bin2hex($raw), bin2hex(var_export($ring->__debugInfo(), true)));
        $this->expectException(VaultException::class);
        serialize($ring);
    }
}
