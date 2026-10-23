<?php

namespace App\Connectors\Iys;

use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;
use App\Webhooks\Contracts\WebhookSignatureVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * İYS push ("İzin Hareketi İletimi") authenticates with HTTP Basic Auth
 * credentials we give İYS at registration. Only a hash of the password is
 * stored (settings.push_username, settings.push_password_hash).
 *
 * Registration itself is a write operation (POST push/registration) and
 * needs İYS support approval, so it is not done in F2; until then the
 * changes pull and daily reconciliation are the primary mechanism.
 */
final class IysPushVerifier implements WebhookSignatureVerifier
{
    public function __construct(private readonly TenantContext $context) {}

    public function verify(Request $request, string $tenantId): string
    {
        $user = $request->getUser();
        $password = $request->getPassword();
        if ($user === null || $password === null) {
            return 'missing';
        }

        $settings = $this->context->run($tenantId, fn () => TenantIntegration::query()
            ->where('provider', 'iys')->where('capability', 'consent')->value('settings'));
        $settings = is_string($settings) ? json_decode($settings, true) : $settings;

        $expectedUser = is_array($settings) ? ($settings['push_username'] ?? null) : null;
        $hash = is_array($settings) ? ($settings['push_password_hash'] ?? null) : null;

        return is_string($expectedUser) && is_string($hash) && hash_equals($expectedUser, $user) && Hash::check($password, $hash)
            ? 'valid'
            : 'invalid';
    }
}
