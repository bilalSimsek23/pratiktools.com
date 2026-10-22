<?php

namespace Tests\Unit;

use App\Security\Vault\Aes256Gcm;
use App\Security\Vault\Exceptions\DecryptionFailed;
use PHPUnit\Framework\TestCase;

final class Aes256GcmTest extends TestCase
{
    public function test_round_trip_with_associated_data(): void
    {
        $key = Aes256Gcm::generateKey();
        $box = Aes256Gcm::encrypt($key, 'hello', 'aad-1');

        $this->assertSame('hello', Aes256Gcm::decrypt($key, $box['nonce'], $box['ciphertext'], $box['tag'], 'aad-1'));
        $this->assertSame(12, strlen($box['nonce']));
        $this->assertSame(16, strlen($box['tag']));
    }

    public function test_nonces_are_unique(): void
    {
        $key = Aes256Gcm::generateKey();

        $this->assertNotSame(Aes256Gcm::encrypt($key, 'x', 'a')['nonce'], Aes256Gcm::encrypt($key, 'x', 'a')['nonce']);
    }

    public function test_wrong_associated_data_fails(): void
    {
        $key = Aes256Gcm::generateKey();
        $box = Aes256Gcm::encrypt($key, 'hello', 'tenant:a');

        $this->expectException(DecryptionFailed::class);
        Aes256Gcm::decrypt($key, $box['nonce'], $box['ciphertext'], $box['tag'], 'tenant:b');
    }

    public function test_wrong_key_fails(): void
    {
        $box = Aes256Gcm::encrypt(Aes256Gcm::generateKey(), 'hello', 'a');

        $this->expectException(DecryptionFailed::class);
        Aes256Gcm::decrypt(Aes256Gcm::generateKey(), $box['nonce'], $box['ciphertext'], $box['tag'], 'a');
    }
}
