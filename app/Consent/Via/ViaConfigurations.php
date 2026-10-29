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
 * - configure(): operator CLI (consent:via) records what the İYS portal
 *   shows; the Form ID is never part of the code base.
 * - setFormStatus(): an authorized, audited act after the status was seen
 *   in the İYS portal. Approval needs an explicit confirmation; nothing
 *   approves a form automatically.
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
     * @param  array{iys_number?: string, brand?: string, package_status?: string, form_type?: string, approval_type?: string, form_id?: string, form_status?: string}  $values
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
        if (isset($values['iys_number']) && preg_match('/^\d{1,12}$/', $values['iys_number']) !== 1) {
            throw new InvalidArgumentException('İYS number is numeric.');
        }
        $values = array_map(fn ($v) => mb_substr(trim($v), 0, 128), $values);

        $tenantId = $this->context->requireTenant();
        DB::transaction(function () use ($tenantId, $values, $actor) {
            $row = DB::table('tenant_integrations')->where('tenant_id', $tenantId)->where('provider', self::PROVIDER)->where('capability', self::CAPABILITY)->lockForUpdate()->first(['id', 'settings']);
            $before = $row === null ? [] : (array) json_decode((string) $row->settings, true);
            $settings = $values + $before + ['form_status' => ViaFormStatus::Draft->value];
            // A different form is not the approved one.
            if (($before['form_status'] ?? null) === ViaFormStatus::Approved->value && ($settings['form_id'] ?? null) !== ($before['form_id'] ?? null)) {
                $settings['form_status'] = ViaFormStatus::PendingApproval->value;
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
            $this->audit->record(AuditAction::ViaConfigured, 'tenant_integration', $id,
                ['changed' => array_keys(array_diff_assoc($settings, $before)), 'form_status' => $settings['form_status']], $actor);
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
}
