<?php

namespace App\Email;

use RuntimeException;

/**
 * Signed unsubscribe tokens: base64url(JSON {v, t: tenant, r: recipient,
 * i: issued at}) "." base64url(HMAC-SHA256). Tenant and recipient bound,
 * tamper-proof, expiring (email.unsubscribe.ttl_days). The key is
 * EMAIL_UNSUBSCRIBE_KEY (32+ random bytes); without it no marketing link
 * can be issued, so marketing sending stays closed.
 */
final class UnsubscribeTokens
{
    public function available(): bool
    {
        return $this->key() !== null;
    }

    public function issue(string $tenantId, string $recipientId): string
    {
        $key = $this->key() ?? throw new RuntimeException('EMAIL_UNSUBSCRIBE_KEY is not configured.');
        $payload = self::b64(json_encode(['v' => 1, 't' => $tenantId, 'r' => $recipientId, 'i' => time()], JSON_THROW_ON_ERROR));

        return $payload.'.'.self::b64(hash_hmac('sha256', $payload, $key, true));
    }

    public function url(string $token): string
    {
        return rtrim((string) config('email.unsubscribe.base_url'), '/').'/u/'.$token;
    }

    /**
     * @return array<string, string> List-Unsubscribe headers (RFC 2369 / 8058 one-click)
     */
    public function headers(string $url): array
    {
        return ['List-Unsubscribe' => "<{$url}>", 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
    }

    /**
     * @return array{tenant_id: string, recipient_id: string, issued_at: int}
     */
    public function verify(string $token): array
    {
        $key = $this->key() ?? throw new InvalidUnsubscribeToken('unavailable');
        $parts = explode('.', $token);
        if (count($parts) !== 2 || strlen($token) > 512) {
            throw new InvalidUnsubscribeToken('malformed');
        }
        if (! hash_equals(self::b64(hash_hmac('sha256', $parts[0], $key, true)), $parts[1])) {
            throw new InvalidUnsubscribeToken('signature');
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (! is_array($data) || ($data['v'] ?? null) !== 1 || ! is_string($data['t'] ?? null) || ! is_string($data['r'] ?? null) || ! is_int($data['i'] ?? null)) {
            throw new InvalidUnsubscribeToken('malformed');
        }
        if ($data['i'] < time() - 86400 * (int) config('email.unsubscribe.ttl_days') || $data['i'] > time() + 300) {
            throw new InvalidUnsubscribeToken('expired');
        }

        return ['tenant_id' => $data['t'], 'recipient_id' => $data['r'], 'issued_at' => $data['i']];
    }

    private function key(): ?string
    {
        $raw = (string) config('email.unsubscribe.key');
        $key = str_starts_with($raw, 'base64:') ? base64_decode(substr($raw, 7), true) : (base64_decode($raw, true) ?: $raw);

        return is_string($key) && strlen($key) >= 32 ? $key : null;
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
