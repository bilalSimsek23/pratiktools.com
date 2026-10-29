<?php

namespace App\Consent\Via;

use App\Consent\ConsentChannel;
use App\Security\SensitiveValue;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ViA OTP for a pending via_consent_requests row, guarded by the ViA
 * status (approved form, Form ID, real transport, no fake in production).
 *
 * - start(): the transport sends the OTP; its provider reference is kept.
 * - verify(): only a verified result with a provider reference and the
 *   requested channel reaches ViaConsentHandler (ONAY, idempotent). Any
 *   other answer records a failure code and creates nothing; the request
 *   stays pending until it succeeds or expires.
 */
final class IysViaOtpProvider implements ViaOtpConsentProvider
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ViaConsentProvider $via,
        private readonly ViaConfigurations $configs,
        private readonly ViaOtpTransport $transport,
        private readonly ViaConsentHandler $handler,
    ) {}

    public function start(string $reference): string
    {
        $config = $this->ready();
        $tenantId = $this->context->requireTenant();

        return DB::transaction(function () use ($config, $tenantId, $reference) {
            $request = $this->pending($reference);
            $address = (string) DB::table('consent_recipients')->where('tenant_id', $tenantId)->where('id', $request->recipient_id)->value('address');
            $result = $this->transport->start($config, new ViaOtpStartRequest($reference, ConsentChannel::from((string) $request->channel), $address));
            if (! $result->accepted || $result->providerReference === null) {
                DB::table('via_consent_requests')->where('tenant_id', $tenantId)->where('id', $request->id)->update(['failure_code' => self::code($result->failureCode ?? 'OTP_NOT_STARTED')]);

                throw new ViaError(self::code($result->failureCode ?? 'OTP_NOT_STARTED'));
            }
            DB::table('via_consent_requests')->where('tenant_id', $tenantId)->where('id', $request->id)
                ->update(['provider_reference' => mb_substr($result->providerReference, 0, 128), 'otp_started_at' => now(), 'failure_code' => null]);

            return $result->providerReference;
        });
    }

    public function verify(string $reference, string $code): string
    {
        $config = $this->ready();
        $tenantId = $this->context->requireTenant();
        $request = DB::table('via_consent_requests')->where('tenant_id', $tenantId)->where('reference', $reference)->first();
        if ($request === null) {
            return 'unknown_reference';
        }
        if ($request->state !== 'pending') {
            return 'duplicate';
        }
        if ($request->provider_reference === null) {
            throw new ViaError('VIA_OTP_NOT_STARTED');
        }

        $result = $this->transport->verify($config, new ViaOtpVerifyRequest($reference, (string) $request->provider_reference, new SensitiveValue($code)));
        $channel = ConsentChannel::from((string) $request->channel);
        if (! $result->verified || $result->providerReference === null || ($result->channel !== null && $result->channel !== $channel)) {
            $failure = ! $result->verified ? ($result->failureCode ?? 'OTP_NOT_VERIFIED') : ($result->providerReference === null ? 'NO_PROVIDER_REFERENCE' : 'CHANNEL_MISMATCH');
            DB::table('via_consent_requests')->where('tenant_id', $tenantId)->where('id', $request->id)
                ->update(['failure_code' => self::code($failure), 'otp_attempts' => DB::raw('otp_attempts + 1')]);

            return 'failed';
        }

        return $this->handler->handle(new ViaConsentEvent(ViaConsentEvent::CONSENT, true, (string) $config->formId, $reference, $result->providerReference,
            $result->occurredAt ?? CarbonImmutable::now(), $channel));
    }

    private function ready(): ViaConfig
    {
        $status = $this->via->status();
        if (! $status->configured()) {
            throw new ViaError((string) $status->blocker(), $status->detail);
        }

        return $this->configs->current() ?? throw new ViaError('VIA_NOT_CONFIGURED');
    }

    private function pending(string $reference): object
    {
        $request = DB::table('via_consent_requests')->where('tenant_id', $this->context->requireTenant())->where('reference', $reference)->lockForUpdate()->first()
            ?? throw new ViaError('VIA_UNKNOWN_REFERENCE');

        return $request->state === 'pending' ? $request : throw new ViaError('VIA_REQUEST_NOT_PENDING');
    }

    private static function code(string $code): string
    {
        $clean = (string) preg_replace('/[^A-Z0-9_]/', '', strtoupper($code));

        return $clean === '' ? 'UNKNOWN' : mb_substr($clean, 0, 64);
    }
}
