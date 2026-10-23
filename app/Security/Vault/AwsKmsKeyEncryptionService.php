<?php

namespace App\Security\Vault;

use App\Security\Vault\Contracts\KeyEncryptionService;
use App\Security\Vault\Exceptions\DecryptionFailed;
use App\Security\Vault\Exceptions\KmsRejected;
use App\Security\Vault\Exceptions\KmsUnavailable;
use App\Security\Vault\Exceptions\VaultException;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\Kms\KmsClient;
use Aws\ResultInterface;

/**
 * Production KEK provider backed by AWS KMS (symmetric key).
 *
 * - Data keys are encrypted and decrypted by KMS; the KEK never leaves it.
 * - The vault's wrap context is sent as the KMS encryption context, so a
 *   wrapped key only decrypts for the same tenant and key version, and
 *   every use is visible in CloudTrail with that context.
 * - Only a full key ARN is accepted: an alias can later point to another
 *   key, which would make older wrapped keys undecryptable. AWS automatic
 *   key rotation keeps the ARN.
 * - Errors are normalized to KmsUnavailable (retryable) or KmsRejected /
 *   DecryptionFailed (permanent).
 */
final class AwsKmsKeyEncryptionService implements KeyEncryptionService
{
    public const KEY_ARN_PATTERN = '/^arn:aws(-[a-z]+)?:kms:([a-z0-9-]+):\d{12}:key\/[0-9a-f-]{36}$/';

    private const PREFIX = 'awskms1:';

    private const CONTEXT_KEY = 'pratiktools';

    /** AWS error codes worth retrying. */
    private const RETRYABLE = [
        'ThrottlingException', 'KMSInternalException', 'DependencyTimeoutException', 'KeyUnavailableException',
        'LimitExceededException', 'RequestTimeout', 'RequestTimeoutException', 'ServiceUnavailable', 'InternalFailure',
    ];

    /** AWS error codes meaning "this ciphertext will never decrypt here". */
    private const UNDECRYPTABLE = ['InvalidCiphertextException', 'IncorrectKeyException'];

    public function __construct(
        private readonly KmsClient $client,
        private readonly string $keyArn,
    ) {
        if (! preg_match(self::KEY_ARN_PATTERN, $keyArn)) {
            throw new VaultException('SECRET_VAULT_AWS_KMS_KEY_ID must be a full KMS key ARN (not an alias).');
        }
    }

    /**
     * @param  array{key_id?: ?string, region?: ?string, access_key_id?: ?string, secret_access_key?: ?string, max_attempts?: int}  $config
     * @param  callable|null  $handler  AWS SDK handler; tests pass a MockHandler
     */
    public static function fromConfig(array $config, ?callable $handler = null): self
    {
        foreach (['key_id', 'region', 'access_key_id', 'secret_access_key'] as $required) {
            if (empty($config[$required])) {
                throw new VaultException("AWS KMS is not configured: {$required} is missing.");
            }
        }

        if (preg_match(self::KEY_ARN_PATTERN, (string) $config['key_id'], $m) && $m[2] !== $config['region']) {
            throw new VaultException('The KMS key ARN region does not match SECRET_VAULT_AWS_REGION.');
        }

        return new self(new KmsClient(array_filter([
            'version' => '2014-11-01',
            'region' => (string) $config['region'],
            'credentials' => new Credentials((string) $config['access_key_id'], (string) $config['secret_access_key']),
            'retries' => ['mode' => 'standard', 'max_attempts' => (int) ($config['max_attempts'] ?? 3)],
            'http' => ['connect_timeout' => 3, 'timeout' => 5],
            'handler' => $handler,
        ], fn ($v) => $v !== null)), (string) $config['key_id']);
    }

    public function currentKeyId(): string
    {
        return $this->keyArn;
    }

    public function wrap(#[\SensitiveParameter] string $dataKey, string $context): string
    {
        $result = $this->call(fn () => $this->client->encrypt([
            'KeyId' => $this->keyArn,
            'Plaintext' => $dataKey,
            'EncryptionContext' => [self::CONTEXT_KEY => $context],
        ]), 'encrypt');

        $blob = $result['CiphertextBlob'] ?? null;
        if (! is_string($blob) || $blob === '') {
            throw new KmsRejected('AWS KMS returned no ciphertext.');
        }

        return self::PREFIX.base64_encode($blob);
    }

    public function unwrap(string $wrappedKey, string $keyId, string $context): string
    {
        if (! str_starts_with($wrappedKey, self::PREFIX)) {
            throw new DecryptionFailed;
        }
        $blob = base64_decode(substr($wrappedKey, strlen(self::PREFIX)), true);
        if ($blob === false || $blob === '') {
            throw new DecryptionFailed;
        }

        $result = $this->call(fn () => $this->client->decrypt([
            'CiphertextBlob' => $blob,
            'KeyId' => $keyId,
            'EncryptionContext' => [self::CONTEXT_KEY => $context],
        ]), 'decrypt');

        $plaintext = $result['Plaintext'] ?? null;
        if (! is_string($plaintext) || strlen($plaintext) !== 32) {
            throw new DecryptionFailed;
        }

        return $plaintext;
    }

    /**
     * @param  callable(): ResultInterface  $request
     * @return array<string, mixed>
     */
    private function call(callable $request, string $operation): array
    {
        try {
            return $request()->toArray();
        } catch (AwsException $e) {
            $code = (string) $e->getAwsErrorCode();
            $label = $code !== '' ? $code : 'network error';

            if (in_array($code, self::UNDECRYPTABLE, true)) {
                throw new DecryptionFailed(previous: $e);
            }
            if ($code === '' || in_array($code, self::RETRYABLE, true) || ($e->getStatusCode() ?? 0) >= 500) {
                throw new KmsUnavailable("AWS KMS {$operation} failed temporarily ({$label}).", $e);
            }

            throw new KmsRejected("AWS KMS {$operation} was rejected ({$label}).", $e);
        }
    }
}
