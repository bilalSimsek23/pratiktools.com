<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Consent\Via\ViaConfigurations;
use App\Consent\Via\ViaConsentProvider;
use App\Consent\Via\ViaFrameReadiness;
use App\Console\Commands\Concerns\RunsInTenantContext;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Records the tenant's İYS ViA form as the İYS portal shows it. The Form ID
 * is read from STDIN (never an argument). Only draft / pending_approval can
 * be set here; approval is recorded in the panel with a portal
 * confirmation. The ViA Frame Widget UUID is stored exactly as given; the
 * domain activation is confirmed only in Super Admin. Nothing here
 * contacts İYS.
 */
final class ConsentViaCommand extends Command
{
    use RunsInTenantContext;

    protected $signature = 'consent:via {--tenant= : Tenant slug} {--iys-number=} {--brand=} {--package= : active|inactive}
        {--form-type= : OTP} {--approval-type= : ETK} {--form-status= : draft|pending_approval} {--form-id-stdin : Read the ViA Form ID from STDIN}
        {--widget-uuid= : ViA Frame Widget UUID, exactly as İYS issued it}';

    protected $description = 'Show or record the İYS ViA form configuration of a tenant';

    public function handle(ViaConfigurations $configs, ViaConsentProvider $via): int
    {
        return $this->inTenant(function () use ($configs, $via): int {
            $values = array_filter([
                'iys_number' => $this->option('iys-number'), 'brand' => $this->option('brand'), 'package_status' => $this->option('package'),
                'form_type' => $this->option('form-type'), 'approval_type' => $this->option('approval-type'), 'form_status' => $this->option('form-status'),
                'form_id' => $this->option('form-id-stdin') ? trim((string) stream_get_contents(STDIN)) : null,
                'widget_uuid' => $this->option('widget-uuid'),
            ], fn ($v) => $v !== null && $v !== '');
            if ($values !== []) {
                try {
                    $configs->configure($values, Actor::console($this->getName()));
                } catch (InvalidArgumentException $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }
            }

            $c = $configs->current();
            $s = $via->status();
            $this->table(['field', 'value'], [
                ['iys_number', $c->iysNumber ?? '—'], ['brand', $c->brand ?? '—'], ['package', $c->packageStatus ?? '—'],
                ['form_type', $c->formType ?? '—'], ['approval_type', $c->approvalType ?? '—'], ['form_id', $c?->maskedFormId() ?? '—'],
                ['form_status', $c?->formStatus->value ?? '—'], ['widget_uuid', $c->widgetUuid ?? '—'], ['frame_origins', implode(' ', $c->frameOrigins ?? []) ?: '—'],
                ['domain_activation', $c?->activatedOrigin() ?? '—'], ['frame_readiness', ViaFrameReadiness::of($c)->state], ['readiness', $s->level()], ['state', $s->state], ['detail', $s->detail],
            ]);

            return self::SUCCESS;
        });
    }
}
