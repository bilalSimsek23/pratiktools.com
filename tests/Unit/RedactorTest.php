<?php

namespace Tests\Unit;

use App\Logging\Redactor;
use App\Security\SensitiveValue;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    public function test_sensitive_keys_are_removed_recursively(): void
    {
        $clean = (new Redactor)->redact([
            'secret' => 's1', 'password' => 'p', 'api_key' => 'k', 'Authorization' => 'Bearer x',
            'nested' => ['client_secret' => 's2', 'token' => 't', 'ok' => 'value'],
            'encrypted_secret' => '{}', 'otp_code' => '123456',
        ]);

        $this->assertSame('[redacted]', $clean['secret']);
        $this->assertSame('[redacted]', $clean['password']);
        $this->assertSame('[redacted]', $clean['api_key']);
        $this->assertSame('[redacted]', $clean['Authorization']);
        $this->assertSame('[redacted]', $clean['nested']['client_secret']);
        $this->assertSame('[redacted]', $clean['nested']['token']);
        $this->assertSame('value', $clean['nested']['ok']);
        $this->assertSame('[redacted]', $clean['encrypted_secret']);
        $this->assertSame('[redacted]', $clean['otp_code']);
    }

    public function test_emails_and_turkish_mobile_numbers_are_masked(): void
    {
        $r = new Redactor;

        $masked = $r->maskString('Contact ayse.yilmaz@example.com or +90 532 123 45 67 / 05321234567');

        $this->assertStringNotContainsString('ayse.yilmaz@example.com', $masked);
        $this->assertStringContainsString('a***@e***.com', $masked);
        $this->assertStringNotContainsString('123 45 67', $masked);
        $this->assertStringNotContainsString('5321234567', $masked);
    }

    public function test_sensitive_values_and_exceptions_are_safe(): void
    {
        $clean = (new Redactor)->redact([
            'value' => new SensitiveValue('top-secret'),
            'error' => new \RuntimeException('failed for user@example.com'),
        ]);

        $this->assertSame('[redacted]', $clean['value']);
        $this->assertSame(\RuntimeException::class, $clean['error']['class']);
        $this->assertStringNotContainsString('user@example.com', $clean['error']['message']);
    }
}
