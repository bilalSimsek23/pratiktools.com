<?php

namespace App\Platform;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Commerce\ConnectionTester;
use App\Commerce\Data\ConnectionCheck;
use App\Commerce\TicimaxIntegrations;
use App\Models\PlatformAdmin;
use App\Models\TenantIntegration;
use App\Tenancy\ProvisioningError;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Super Admin › Müşteriler › Entegrasyonlar › Ticimax: the tenant's Ticimax
 * commerce integration (provider "ticimax", capability "commerce"), read and
 * changed only inside that tenant's context (runtime role, RLS), like every
 * other Super Admin change. The platform role gets no access to integration
 * settings or secrets.
 *
 * The member code (UyeKodu) is written through TicimaxIntegrations into the
 * vault and is never read back here: the screen only learns whether one is
 * stored and when it last changed. The connection test is the existing
 * read-only ConnectionTester; nothing here starts a sync.
 */
final class TenantTicimax
{
    private const SECRET_ACTIONS = [AuditAction::IntegrationSecretCreated, AuditAction::IntegrationSecretChanged];

    public function __construct(
        private readonly TenantContext $context,
        private readonly TicimaxIntegrations $integrations,
        private readonly ConnectionTester $tester,
    ) {}

    /**
     * Metadata only: never the member code or its ciphertext.
     *
     * @return array{integration: ?array{status: string, has_secret: bool, service_url: ?string, storefront_url: ?string, last_verified_at: ?string, last_error: ?string, secret_changed_at: ?string, secret_changed_by: ?string}}
     */
    public function show(string $tenantId): array
    {
        $row = $this->context->run($this->id($tenantId), function () {
            $i = DB::table('tenant_integrations')->where('provider', 'ticimax')->where('capability', 'commerce')
                ->select(['id', 'status', 'settings', 'last_verified_at', 'last_error'])->selectRaw('encrypted_secret IS NOT NULL AS has_secret')->first();
            if ($i === null) {
                return null;
            }
            $i->secret_change = DB::table('audit_logs')->where('resource_type', 'tenant_integration')->where('resource_id', $i->id)
                ->whereIn('action', array_map(fn (AuditAction $a) => $a->value, self::SECRET_ACTIONS))->orderByDesc('created_at')->first(['actor_type', 'actor_id', 'created_at']);

            return $i;
        });
        if ($row === null) {
            return ['integration' => null];
        }
        $settings = (array) json_decode((string) $row->settings, true);

        return ['integration' => [
            'status' => (string) $row->status,
            'has_secret' => (bool) $row->has_secret,
            'service_url' => $settings['service_url'] ?? null,
            'storefront_url' => $settings['storefront_url'] ?? null,
            'last_verified_at' => $row->last_verified_at,
            'last_error' => $row->last_error,
            'secret_changed_at' => $row->secret_change?->created_at,
            'secret_changed_by' => $row->secret_change === null ? null : $this->actorLabel($row->secret_change->actor_type, $row->secret_change->actor_id),
        ]];
    }

    /**
     * An empty member code keeps the stored one; a new one replaces it.
     */
    public function configure(string $tenantId, string $serviceUrl, string $storefrontUrl, #[\SensitiveParameter] ?string $secret, Actor $actor): void
    {
        try {
            $this->context->run($this->id($tenantId), fn () => $this->integrations->save($serviceUrl, $storefrontUrl, $secret, $actor));
        } catch (InvalidArgumentException $e) {
            throw new ProvisioningError(match (true) {
                str_starts_with($e->getMessage(), 'Invalid URL') => 'Adres geçersiz: yalnız herkese açık https:// adresleri kabul edilir.',
                default => 'İlk kurulumda Ticimax yetki kodu (UyeKodu) zorunludur.',
            });
        }
    }

    /**
     * The existing read-only connection test (the same one commerce:test and
     * the API run): it updates status, last_verified_at and last_error.
     */
    public function test(string $tenantId, Actor $actor): ConnectionCheck
    {
        return $this->context->run($this->id($tenantId), function () use ($actor) {
            $integration = TenantIntegration::query()->where('provider', 'ticimax')->where('capability', 'commerce')->first()
                ?? throw new ProvisioningError('Bu müşteri için Ticimax entegrasyonu yok.');
            if ($integration->encrypted_secret === null) {
                throw new ProvisioningError('Önce Ticimax yetki kodunu kaydedin.');
            }

            return $this->tester->test($integration, $actor);
        });
    }

    private function actorLabel(string $type, ?string $id): string
    {
        return match ($type) {
            'platform_admin' => 'Platform yöneticisi'.(($name = $id === null ? null : PlatformAdmin::query()->whereKey($id)->value('name')) ? " ({$name})" : ''),
            'console' => 'Sunucu komutu'.($id ? " ({$id})" : ''),
            'user' => 'Müşteri kullanıcısı',
            default => $type,
        };
    }

    private function id(string $tenantId): string
    {
        return Str::isUuid($tenantId) ? $tenantId : throw new ProvisioningError('Müşteri bulunamadı.');
    }
}
