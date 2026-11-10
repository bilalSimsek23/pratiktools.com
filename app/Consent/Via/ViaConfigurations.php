<?php

namespace App\Consent\Via;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Stored ViA configuration of the tenant and its form status.
 *
 * - configure(): operator CLI (consent:via) or Super Admin (Müşteriler ›
 *   Entegrasyonlar › İYS / ViA) records what the İYS portal shows and the
 *   ViA Frame origins; the Form ID is never part of the code base.
 * - setFormStatus(): an authorized, audited act after the status was seen
 *   in the İYS portal. Approval needs an explicit confirmation; nothing
 *   approves a form automatically.
 * - confirmDomainActivation(): a Super Admin records that İYS activated
 *   the ViA Frame for one origin. Changing the Widget UUID or the origins
 *   drops the confirmation; nothing confirms it automatically, and no
 *   browser (Frame) event ever does.
 */
final class ViaConfigurations
{
    public const PROVIDER = 'via';

    public const CAPABILITY = 'consent_acquisition';

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function current(): ?ViaConfig
    {
        $row = DB::table('tenant_integrations')->where('tenant_id', $this->context->requireTenant())
            ->where('provider', self::PROVIDER)->where('capability', self::CAPABILITY)->first(['id', 'settings']);

        return $row === null ? null : ViaConfig::fromSettings((string) $row->id, (array) json_decode((string) $row->settings, true));
    }

    /**
     * @param  array{iys_number?: string, brand?: string, package_status?: string, form_type?: string, approval_type?: string, form_id?: string, form_status?: string, frame_origins?: list<string>, widget_uuid?: string}  $values
     */
    public function configure(array $values, Actor $actor): ViaConfig
    {
        if (isset($values['form_type']) && ! in_array($values['form_type'], ViaConfig::FORM_TYPES, true)) {
            throw new InvalidArgumentException('Only the OTP form type is supported.');
        }
        if (isset($values['approval_type']) && ! in_array($values['approval_type'], ViaConfig::APPROVAL_TYPES, true)) {
            throw new InvalidArgumentException('Only the ETK approval type is supported.');
        }
        // Approval only through setFormStatus (portal confirmation, audited).
        if (isset($values['form_status']) && ! in_array($values['form_status'], [ViaFormStatus::Draft->value, ViaFormStatus::PendingApproval->value], true)) {
            throw new InvalidArgumentException('Configuration can only set draft or pending_approval; record other statuses with the form status action.');
        }
        if (isset($values['package_status']) && ! in_array($values['package_status'], ['active', 'inactive'], true)) {
            throw new InvalidArgumentException('Package status is active or inactive.');
        }
        if (isset($values['form_id']) && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $values['form_id']) !== 1) {
            throw new InvalidArgumentException('Form ID has an unexpected format.');
        }
        // Exactly as İYS gave it: format checked, case never changed.
        if (isset($values['widget_uuid']) && ! Str::isUuid(trim($values['widget_uuid']))) {
            throw new InvalidArgumentException('Widget UUID must be a UUID.');
        }
        if (isset($values['iys_number']) && preg_match('/^\d{1,12}$/', $values['iys_number']) !== 1) {
            throw new InvalidArgumentException('İYS number is numeric.');
        }
        if (array_key_exists('frame_origins', $values)) {
            $values['frame_origins'] = ViaFrameOrigins::normalize((array) $values['frame_origins']);
        }
        $values = array_map(fn ($v) => is_array($v) ? $v : mb_substr(trim((string) $v), 0, 128), $values);

        $tenantId = $this->context->requireTenant();
        DB::transaction(function () use ($tenantId, $values, $actor) {
            $row = DB::table('tenant_integrations')->where('tenant_id', $tenantId)->where('provider', self::PROVIDER)->where('capability', self::CAPABILITY)->lockForUpdate()->first(['id', 'settings']);
            $before = $row === null ? [] : (array) json_decode((string) $row->settings, true);
            $settings = $values + $before + ['form_status' => ViaFormStatus::Draft->value];
            // A different form is not the approved one.
            if (($before['form_status'] ?? null) === ViaFormStatus::Approved->value && ($settings['form_id'] ?? null) !== ($before['form_id'] ?? null)) {
                $settings['form_status'] = ViaFormStatus::PendingApproval->value;
            }
            // The activation was for that Widget UUID and those origins only.
            if (isset($settings['domain_activation']) && (($settings['widget_uuid'] ?? null) !== ($before['widget_uuid'] ?? null)
                || json_encode($settings['frame_origins'] ?? []) !== json_encode($before['frame_origins'] ?? []))) {
                unset($settings['domain_activation']);
            }
            if ($row === null) {
                $id = (string) Str::uuid7();
                DB::table('tenant_integrations')->insert(['id' => $id, 'tenant_id' => $tenantId, 'provider' => self::PROVIDER, 'capability' => self::CAPABILITY,
                    'status' => 'pending', 'settings' => json_encode($settings), 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $id = (string) $row->id;
                DB::table('tenant_integrations')->where('tenant_id', $tenantId)->where('id', $id)->update(['settings' => json_encode($settings), 'updated_at' => now()]);
            }
            // Which fields changed, never the Form ID itself.
            $changed = array_keys(array_filter($settings, fn ($v, $k) => json_encode($v) !== json_encode($before[$k] ?? null), ARRAY_FILTER_USE_BOTH));
            $meta = ['changed' => $changed, 'form_status' => $settings['form_status'], 'created' => $row === null];
            if (isset($before['domain_activation']) && ! isset($settings['domain_activation'])) {
                $meta['domain_activation_cleared'] = true;
            }
            $this->audit->record(AuditAction::ViaConfigured, 'tenant_integration', $id, $meta, $actor);
        });

        return $this->current() ?? throw new \LogicException('ViA configuration missing after save.');
    }

    public function setFormStatus(ViaFormStatus $to, Actor $actor, string $note, bool $portalConfirmed = false): void
    {
        if (trim($note) === '') {
            throw new InvalidArgumentException('A note is required.');
        }
        if ($to === ViaFormStatus::Approved && ! $portalConfirmed) {
            throw new InvalidArgumentException('Approval must be confirmed as seen in the İYS portal.');
        }
        $tenantId = $this->context->requireTenant();
        DB::transaction(function () use ($tenantId, $to, $actor, $note) {
            $row = DB::table('tenant_integrations')->where('tenant_id', $tenantId)->where('provider', self::PROVIDER)->where('capability', self::CAPABILITY)->lockForUpdate()->first(['id', 'settings'])
                ?? throw new InvalidArgumentException('No ViA configuration.');
            $settings = (array) json_decode((string) $row->settings, true);
            $from = ViaFormStatus::tryFrom((string) ($settings['form_status'] ?? '')) ?? ViaFormStatus::Draft;
            if (! in_array($to, $from->next(), true)) {
                throw new InvalidArgumentException("A form cannot go from {$from->value} to {$to->value}.");
            }
            if ($to === ViaFormStatus::Approved && trim((string) ($settings['form_id'] ?? '')) === '') {
                throw new InvalidArgumentException('An approved form needs its Form ID.');
            }
            $settings['form_status'] = $to->value;
            DB::table('tenant_integrations')->where('tenant_id', $tenantId)->where('id', $row->id)->update(['settings' => json_encode($settings), 'updated_at' => now()]);
            $this->audit->record(AuditAction::ViaFormStatusChanged, 'tenant_integration', (string) $row->id,
                ['from' => $from->value, 'to' => $to->value, 'note' => mb_substr(trim($note), 0, 255), 'portal_confirmed' => $to === ViaFormStatus::Approved], $actor);
        });
    }

    /**
     * Records that İYS activated the ViA Frame for $origin (one of the
     * stored Frame origins) with the stored Widget UUID, as seen in İYS's
     * notice. Bound to the current Widget UUID and origin list.
     */
    public function confirmDomainActivation(string $origin, string $note, bool $iysConfirmed, Actor $actor): void
    {
        if (! $iysConfirmed) {
            throw new InvalidArgumentException('The domain activation must be confirmed as seen in İYS\'s notice.');
        }
        if (trim($note) === '') {
            throw new InvalidArgumentException('A note is required.');
        }
        $origin = ViaFrameOrigins::normalize([$origin])[0] ?? throw new InvalidArgumentException('An origin is required.');
        $tenantId = $this->context->requireTenant();
        DB::transaction(function () use ($tenantId, $origin, $note, $actor) {
            $row = DB::table('tenant_integrations')->where('tenant_id', $tenantId)->where('provider', self::PROVIDER)->where('capability', self::CAPABILITY)->lockForUpdate()->first(['id', 'settings'])
                ?? throw new InvalidArgumentException('No ViA configuration.');
            $settings = (array) json_decode((string) $row->settings, true);
            $widget = is_string($settings['widget_uuid'] ?? null) ? $settings['widget_uuid'] : '';
            if (! Str::isUuid($widget)) {
                throw new InvalidArgumentException('Record the Widget UUID first.');
            }
            $origins = array_values(array_filter((array) ($settings['frame_origins'] ?? []), 'is_string'));
            if (! in_array($origin, $origins, true)) {
                throw new InvalidArgumentException('The activated origin must be one of the stored Frame origins.');
            }
            $settings['domain_activation'] = ['origin' => $origin, 'widget_uuid' => $widget, 'frame_origins' => $origins,
                'confirmed_at' => now()->toIso8601String(), 'confirmed_by' => $actor->id, 'note' => mb_substr(trim($note), 0, 255)];
            DB::table('tenant_integrations')->where('tenant_id', $tenantId)->where('id', $row->id)->update(['settings' => json_encode($settings), 'updated_at' => now()]);
            $this->audit->record(AuditAction::ViaDomainActivationConfirmed, 'tenant_integration', (string) $row->id,
                ['origin' => $origin, 'note' => mb_substr(trim($note), 0, 255), 'iys_confirmed' => true], $actor);
        });
    }
}
