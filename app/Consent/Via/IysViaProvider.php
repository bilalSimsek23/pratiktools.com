<?php

namespace App\Consent\Via;

/**
 * The tenant's İYS ViA, from its stored configuration. Usable for
 * production consent acquisition only when every condition holds:
 *
 *  configuration exists, OTP form, ETK approval, not rejected/disabled,
 *  a Form ID, status approved (recorded after seeing it in the İYS
 *  portal), an OTP transport that is configured, and never a fake one in
 *  production.
 *
 * Inbound ViA events are refused: no official event format exists yet.
 */
final class IysViaProvider implements ViaConsentProvider
{
    public function __construct(
        private readonly ViaConfigurations $configs,
        private readonly ViaOtpTransport $transport,
    ) {}

    public function status(): ViaStatus
    {
        $c = $this->configs->current();

        return match (true) {
            $c === null => new ViaStatus(ViaStatus::NOT_CONFIGURED, 'No ViA configuration for this tenant.'),
            ! in_array($c->formType, ViaConfig::FORM_TYPES, true) || ! in_array($c->approvalType, ViaConfig::APPROVAL_TYPES, true) => new ViaStatus(ViaStatus::UNSUPPORTED_FORM, 'Only an OTP form with ETK approval is supported.'),
            $c->formStatus === ViaFormStatus::Rejected => new ViaStatus(ViaStatus::FORM_REJECTED, 'The ViA form was rejected by İYS.'),
            $c->formStatus === ViaFormStatus::Disabled => new ViaStatus(ViaStatus::FORM_DISABLED, 'The ViA form is disabled.'),
            $c->formId === null => new ViaStatus(ViaStatus::FORM_ID_MISSING, 'The ViA Form ID is not configured.'),
            $c->formStatus === ViaFormStatus::Draft => new ViaStatus(ViaStatus::FORM_DRAFT, 'The ViA form has not been submitted for İYS approval.'),
            $c->formStatus === ViaFormStatus::PendingApproval => new ViaStatus(ViaStatus::FORM_PENDING_APPROVAL, 'ViA OTP form is awaiting İYS approval.'),
            $this->transport->isFake() && app()->isProduction() => new ViaStatus(ViaStatus::FAKE_TRANSPORT_IN_PRODUCTION, 'A test ViA transport is bound in production.'),
            ! $this->transport->configured() => new ViaStatus(ViaStatus::TRANSPORT_NOT_CONFIGURED, 'Waiting for the official İYS ViA OTP integration specification.'),
            default => new ViaStatus(ViaStatus::CONFIGURED, 'ViA OTP is ready.'),
        };
    }

    public function frame(): ?ViaFrameConfig
    {
        $c = $this->configs->current();

        return $c === null || $c->formId === null ? null : new ViaFrameConfig($c->integrationId, $c->formId, 'production', $c->formType, $c->approvalType);
    }

    public function verifyEvent(array $payload, array $headers): ViaConsentEvent
    {
        throw new ViaError('VIA_EVENT_FORMAT_UNVERIFIED', 'ViA events cannot be verified until the official event format is available.');
    }
}
