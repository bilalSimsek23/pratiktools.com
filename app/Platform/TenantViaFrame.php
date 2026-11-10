<?php

namespace App\Platform;

use App\Audit\Actor;
use App\Consent\Via\ViaConfig;
use App\Consent\Via\ViaConfigurations;
use App\Consent\Via\ViaFormStatus;
use App\Consent\Via\ViaFrameReadiness;
use App\Tenancy\ProvisioningError;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Super Admin › Müşteriler › Entegrasyonlar › İYS / ViA: the tenant's own
 * ViA configuration (provider "via", capability "consent_acquisition"),
 * read and changed only inside that tenant's context (runtime role, RLS),
 * like every other Super Admin change. The platform role gets no access to
 * integration settings.
 *
 * Configuration only: nothing here contacts ViA or İYS, starts an OTP or
 * writes consent. The tenant embeds the official ViA Frame, which runs the
 * OTP and records the consent at İYS itself.
 */
final class TenantViaFrame
{
    public function __construct(private readonly TenantContext $context, private readonly ViaConfigurations $configs) {}

    /**
     * @return array{config: ?ViaConfig, readiness: ViaFrameReadiness, snippet: ?string}
     */
    public function show(string $tenantId): array
    {
        $config = $this->context->run($this->id($tenantId), fn () => $this->configs->current());
        $readiness = ViaFrameReadiness::of($config);

        return ['config' => $config, 'readiness' => $readiness, 'snippet' => $config === null ? null : $readiness->snippet($config)];
    }

    /**
     * @param  array{iys_number?: string, brand?: string, package_status?: string, form_id?: string, widget_uuid?: string, frame_origins?: list<string>}  $values
     */
    public function configure(string $tenantId, array $values, Actor $actor): void
    {
        // Both stored exactly as İYS shows them: trimmed, case never changed.
        if (isset($values['form_id'])) {
            $values['form_id'] = trim($values['form_id']);
            if (! ViaFrameReadiness::validFormId($values['form_id'])) {
                throw new ProvisioningError('ViA portal Form ID bir UUID olmalı (ör. 123e4567-e89b-12d3-a456-426614174000).');
            }
        }
        if (isset($values['widget_uuid'])) {
            $values['widget_uuid'] = trim($values['widget_uuid']);
            if (! ViaFrameReadiness::validWidgetUuid($values['widget_uuid'])) {
                throw new ProvisioningError('ViA Frame Widget UUID bir UUID olmalı (İYS aktivasyon bildirimindeki değer; DNS TXT doğrulama değeri değil).');
            }
        }
        // The only form and approval types ViA offers this tenant today.
        $values += ['form_type' => 'OTP', 'approval_type' => 'ETK'];
        try {
            $this->context->run($this->id($tenantId), fn () => $this->configs->configure($values, $actor));
        } catch (InvalidArgumentException $e) {
            throw new ProvisioningError($e->getMessage());
        }
    }

    public function setFormStatus(string $tenantId, ViaFormStatus $to, string $note, bool $portalConfirmed, Actor $actor): void
    {
        try {
            $this->context->run($this->id($tenantId), fn () => $this->configs->setFormStatus($to, $actor, $note, $portalConfirmed));
        } catch (InvalidArgumentException $e) {
            throw new ProvisioningError($e->getMessage());
        }
    }

    public function confirmDomainActivation(string $tenantId, string $origin, string $note, bool $iysConfirmed, Actor $actor): void
    {
        try {
            $this->context->run($this->id($tenantId), fn () => $this->configs->confirmDomainActivation($origin, $note, $iysConfirmed, $actor));
        } catch (InvalidArgumentException $e) {
            throw new ProvisioningError($e->getMessage());
        }
    }

    private function id(string $tenantId): string
    {
        return Str::isUuid($tenantId) ? $tenantId : throw new ProvisioningError('Müşteri bulunamadı.');
    }
}
