<?php

namespace App\Platform;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Models\Tenant;
use App\Site\SiteOwner;
use App\Tenancy\ProvisioningError;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Super Admin edits of a tenant's basic data: name, code (slug), contact
 * phone, country, timezone, currency, locale. Never the id, public id,
 * status, owner, data key or plan. Runs in the tenant's own context (RLS).
 *
 * The slug is only an operator handle: every relation, job, session, API
 * token and webhook uses the tenant id or public id. Changing it needs the
 * new code typed again; the website owner's code cannot change here (the
 * website finds its owner by code). The old code becomes free.
 *
 * Audit tenant.metadata_updated: old/new values of the business fields;
 * for the contact phone only that it changed.
 */
final class TenantMetadata
{
    public const FIELDS = ['name', 'slug', 'contact_phone', 'country', 'timezone', 'currency', 'locale'];

    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit, private readonly SiteOwner $siteOwner) {}

    /**
     * @param  array<string, mixed>  $values  any of FIELDS
     * @return list<string> the fields that changed
     */
    public function update(string $tenantId, array $values, ?string $slugConfirmation, Actor $actor): array
    {
        if (! Str::isUuid($tenantId)) {
            throw new ProvisioningError('Müşteri bulunamadı.');
        }
        $new = $this->clean($values);
        $isSiteOwner = $this->siteOwner->is($tenantId);

        try {
            return $this->context->run($tenantId, fn () => DB::transaction(function () use ($tenantId, $new, $slugConfirmation, $isSiteOwner, $actor) {
                $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->first() ?? throw new ProvisioningError('Müşteri bulunamadı.');
                $changes = array_filter($new, fn ($v, $k) => $v !== $tenant->getAttribute($k), ARRAY_FILTER_USE_BOTH);
                if ($changes === []) {
                    return [];
                }
                if (array_key_exists('slug', $changes)) {
                    if ($isSiteOwner) {
                        throw new ProvisioningError('Bu müşteri pratiktools.com web sitesinin sahibi; kodu buradan değiştirilemez (web sitesi sahibini koduyla bulur).');
                    }
                    if ($slugConfirmation !== $changes['slug']) {
                        throw new ProvisioningError('Kod değişikliği için yeni kodu onay alanına aynen yazın.');
                    }
                }
                $before = array_map(fn ($k) => $tenant->getAttribute($k), array_combine(array_keys($changes), array_keys($changes)));
                $tenant->forceFill($changes)->save();

                $metadata = ['changed' => array_keys($changes)];
                foreach ($changes as $k => $v) {
                    if ($k !== 'contact_phone') {
                        $metadata['from'][$k] = $before[$k];
                        $metadata['to'][$k] = $v;
                    }
                }
                $this->audit->record(AuditAction::TenantMetadataUpdated, 'tenant', $tenantId, $metadata, $actor);

                return array_keys($changes);
            }));
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw new ProvisioningError('Bu kod başka bir müşteride kullanılıyor; farklı bir kod seçin.');
            }
            throw $e;
        }
    }

    /**
     * The given FIELDS, validated and normalized; anything else is ignored.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, ?string>
     */
    private function clean(array $values): array
    {
        $out = [];
        foreach (self::FIELDS as $k) {
            if (! array_key_exists($k, $values)) {
                continue;
            }
            $v = trim((string) $values[$k]);
            $out[$k] = match ($k) {
                'name' => mb_strlen($v) >= 2 && mb_strlen($v) <= 160 ? $v : throw new ProvisioningError('Şirket adı 2–160 karakter olmalı.'),
                'slug' => TenantProvisioner::validSlug(Str::lower($v)) ? Str::lower($v) : throw new ProvisioningError('Geçersiz kod: küçük harf, rakam ve tek tire; 2–63 karakter; ayrılmış kelimeler kullanılamaz.'),
                'contact_phone' => $v === '' ? null : (preg_match('/^\+?[0-9 ()-]{7,24}$/', $v) === 1 ? $v : throw new ProvisioningError('Telefon numarası geçersiz.')),
                'country' => preg_match('/^[A-Za-z]{2}$/', $v) === 1 ? Str::upper($v) : throw new ProvisioningError('Ülke iki harfli ISO kodu olmalı (ör. TR).'),
                'timezone' => in_array($v, \DateTimeZone::listIdentifiers(), true) ? $v : throw new ProvisioningError('Geçersiz saat dilimi.'),
                'currency' => preg_match('/^[A-Za-z]{3}$/', $v) === 1 ? Str::upper($v) : throw new ProvisioningError('Para birimi üç harfli ISO kodu olmalı (ör. TRY).'),
                'locale' => preg_match('/^[a-z]{2}(?:[-_][A-Z]{2})?$/', $v) === 1 ? $v : throw new ProvisioningError('Dil kodu geçersiz (ör. tr veya tr-TR).'),
            };
        }

        return $out;
    }
}
