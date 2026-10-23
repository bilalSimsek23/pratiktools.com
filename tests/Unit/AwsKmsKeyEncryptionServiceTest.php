<?php

namespace Tests\Unit;

use App\Security\Vault\AwsKmsKeyEncryptionService;
use App\Security\Vault\Contracts\KeyEncryptionService;
use App\Security\Vault\Exceptions\DecryptionFailed;
use App\Security\Vault\Exceptions\KmsRejected;
use App\Security\Vault\Exceptions\KmsUnavailable;
use App\Security\Vault\Exceptions\VaultException;
use App\Security\Vault\LocalKeyEncryptionService;
use Aws\CommandInterface;
use Aws\Kms\Exception\KmsException;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The AWS KMS adapter against an in-memory KMS (SDK handler), plus a
 * contract every KeyEncryptionService must meet. No network, no real
 * credentials: the values below are fake.
 */
final class AwsKmsKeyEncryptionServiceTest extends TestCase
{
    private const ARN = 'arn:aws:kms:eu-central-1:123456789012:key/1234abcd-12ab-34cd-56ef-1234567890ab';

    private const FAKE_ACCESS_KEY = 'AKIAFAKEFAKEFAKEFAKE';

    private const FAKE_SECRET = 'fake-secret-access-key-do-not-use-0000000';

    /** @var list<array{name: string, params: array<string, mixed>}> */
    private array $calls = [];

    /** @var array<string, array{plaintext: string, context: array<string, string>, key: string}> */
    private array $store = [];

    private ?string $failWith = null;

    private function kms(int $attempts = 1): AwsKmsKeyEncryptionService
    {
        $handler = function (CommandInterface $command, RequestInterface $request) {
            $this->calls[] = ['name' => $command->getName(), 'params' => $command->toArray()];

            if ($this->failWith !== null) {
                return Create::rejectionFor(new KmsException('simulated', $command, $this->failWith === 'network' ? [] : ['code' => $this->failWith, 'response' => null]));
            }

            if ($command->getName() === 'Encrypt') {
                $blob = 'blob-'.bin2hex(random_bytes(8));
                $this->store[$blob] = ['plaintext' => $command['Plaintext'], 'context' => $command['EncryptionContext'], 'key' => $command['KeyId']];

                return Create::promiseFor(new Result(['CiphertextBlob' => $blob, 'KeyId' => $command['KeyId']]));
            }

            $entry = $this->store[$command['CiphertextBlob']] ?? null;
            if ($entry === null || $entry['context'] !== $command['EncryptionContext'] || $entry['key'] !== $command['KeyId']) {
                return Create::rejectionFor(new KmsException('denied', $command, ['code' => 'InvalidCiphertextException']));
            }

            return Create::promiseFor(new Result(['Plaintext' => $entry['plaintext'], 'KeyId' => $entry['key']]));
        };

        return AwsKmsKeyEncryptionService::fromConfig([
            'key_id' => self::ARN,
            'region' => 'eu-central-1',
            'access_key_id' => self::FAKE_ACCESS_KEY,
            'secret_access_key' => self::FAKE_SECRET,
            'max_attempts' => $attempts,
        ], $handler);
    }

    /**
     * @return array<string, array{0: callable(self): KeyEncryptionService}>
     */
    public static function implementations(): array
    {
        return [
            'local' => [fn () => LocalKeyEncryptionService::fromConfig(['key_id' => 'local-k1', 'key' => base64_encode(random_bytes(32))])],
            'aws_kms' => [fn (self $test) => $test->kms()],
        ];
    }

    #[DataProvider('implementations')]
    public function test_contract_round_trip_and_context_binding(callable $make): void
    {
        $kms = $make($this);
        $dek = random_bytes(32);

        $wrapped = $kms->wrap($dek, 'tenant:a|dek:v1');

        $this->assertIsString($wrapped);
        $this->assertStringNotContainsString($dek, $wrapped);
        $this->assertSame($dek, $kms->unwrap($wrapped, $kms->currentKeyId(), 'tenant:a|dek:v1'));

        $this->expectException(VaultException::class);
        $kms->unwrap($wrapped, $kms->currentKeyId(), 'tenant:b|dek:v1');
    }

    public function test_encrypt_and_decrypt_send_key_arn_and_encryption_context(): void
    {
        $kms = $this->kms();
        $wrapped = $kms->wrap(random_bytes(32), 'tenant:a|dek:v1');
        $kms->unwrap($wrapped, self::ARN, 'tenant:a|dek:v1');

        [$encrypt, $decrypt] = $this->calls;
        $this->assertSame(['Encrypt', 'Decrypt'], [$encrypt['name'], $decrypt['name']]);
        $this->assertSame(self::ARN, $encrypt['params']['KeyId']);
        $this->assertSame(['pratiktools' => 'tenant:a|dek:v1'], $encrypt['params']['EncryptionContext']);
        $this->assertSame(self::ARN, $decrypt['params']['KeyId']);
        $this->assertSame(['pratiktools' => 'tenant:a|dek:v1'], $decrypt['params']['EncryptionContext']);
        $this->assertStringStartsWith('awskms1:', $wrapped);
        $this->assertSame(self::ARN, $kms->currentKeyId());
    }

    /**
     * @return array<string, array{0: string, 1: class-string}>
     */
    public static function errors(): array
    {
        return [
            'throttling' => ['ThrottlingException', KmsUnavailable::class],
            'internal' => ['KMSInternalException', KmsUnavailable::class],
            'dependency timeout' => ['DependencyTimeoutException', KmsUnavailable::class],
            'network' => ['network', KmsUnavailable::class],
            'access denied' => ['AccessDeniedException', KmsRejected::class],
            'disabled key' => ['DisabledException', KmsRejected::class],
            'missing key' => ['NotFoundException', KmsRejected::class],
            'invalid state' => ['KMSInvalidStateException', KmsRejected::class],
            'bad ciphertext' => ['InvalidCiphertextException', DecryptionFailed::class],
            'other key' => ['IncorrectKeyException', DecryptionFailed::class],
        ];
    }

    /**
     * @param  class-string  $expected
     */
    #[DataProvider('errors')]
    public function test_errors_are_normalized_and_never_reveal_credentials(string $code, string $expected): void
    {
        $kms = $this->kms();
        $this->failWith = $code;

        try {
            $code === 'InvalidCiphertextException' || $code === 'IncorrectKeyException'
                ? $kms->unwrap('awskms1:'.base64_encode('x'), self::ARN, 'c')
                : $kms->wrap(random_bytes(32), 'c');
            $this->fail('Expected an exception.');
        } catch (VaultException $e) {
            $this->assertInstanceOf($expected, $e);
            if ($e instanceof KmsUnavailable) {
                $this->assertTrue($e->isRetryable());
            }
            if ($e instanceof KmsRejected) {
                $this->assertFalse($e->isRetryable());
            }
            $this->assertStringNotContainsString(self::FAKE_ACCESS_KEY, $e->getMessage());
            $this->assertStringNotContainsString(self::FAKE_SECRET, $e->getMessage());
        }
    }

    public function test_aliases_region_mismatch_and_missing_settings_are_refused(): void
    {
        foreach ([
            ['key_id' => 'alias/pratiktools', 'region' => 'eu-central-1'],
            ['key_id' => str_replace('eu-central-1', 'us-east-1', self::ARN), 'region' => 'eu-central-1'],
            ['key_id' => self::ARN, 'region' => 'eu-central-1', 'access_key_id' => ''],
        ] as $config) {
            try {
                AwsKmsKeyEncryptionService::fromConfig($config + ['access_key_id' => self::FAKE_ACCESS_KEY, 'secret_access_key' => self::FAKE_SECRET]);
                $this->fail('Expected a configuration error.');
            } catch (VaultException $e) {
                $this->assertStringNotContainsString(self::FAKE_SECRET, $e->getMessage());
            }
        }
    }

    public function test_tampered_or_foreign_wrapped_keys_fail_closed(): void
    {
        $kms = $this->kms();

        foreach (['', 'not-ours', 'awskms1:', 'awskms1:!!!'] as $wrapped) {
            try {
                $kms->unwrap($wrapped, self::ARN, 'c');
                $this->fail('Expected DecryptionFailed.');
            } catch (DecryptionFailed) {
            }
        }
        $this->assertSame([], $this->calls, 'Malformed input never reaches KMS.');
    }
}
