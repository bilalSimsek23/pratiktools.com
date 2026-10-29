<?php

namespace App\Site;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Website references, fully managed in the panel (no code, deploy or
 * migration for any change). Every change is audited and bumps the site
 * cache version.
 *
 * Publication rules:
 * - public = published AND active AND client-name approval recorded;
 * - publishing refuses without the client-name approval;
 * - a logo is shown only with a separate logo approval (never assumed);
 *   without it the name is shown as text;
 * - renaming drops the name approval, a new logo drops the logo approval.
 */
final class SiteReferences
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SiteMedia $media,
    ) {}

    public function create(string $name, Actor $actor): string
    {
        $name = SiteContents::line($name, 160);
        if ($name === '') {
            throw new SiteError('Firma / referans adı gerekli.');
        }
        $base = SiteContents::slug($name) ?: 'referans';
        $slug = $base;
        for ($i = 2; DB::table('site_references')->where('slug', $slug)->exists(); $i++) {
            $slug = mb_substr($base, 0, 130).'-'.$i;
        }
        $id = (string) Str::uuid7();
        DB::table('site_references')->insert(['id' => $id, 'slug' => $slug, 'name' => $name, 'featured_order' => (int) DB::table('site_references')->max('featured_order') + 1,
            'status' => ContentStatus::Draft->value, 'created_by' => self::by($actor), 'updated_by' => self::by($actor), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record(AuditAction::SiteReferenceCreated, 'site_reference', $id, ['slug' => $slug], $actor);
        SiteContents::bump();

        return $id;
    }

    /**
     * @param  array<string, mixed>  $input  name, slug, website_url, industry, short_description, body, capabilities (text, one per line), contents (ids), active
     */
    public function update(string $id, array $input, Actor $actor): void
    {
        DB::transaction(function () use ($id, $input, $actor) {
            $row = $this->find($id, lock: true);
            $name = SiteContents::line((string) ($input['name'] ?? $row->name), 160);
            $slug = SiteContents::slug((string) ($input['slug'] ?? $row->slug));
            if ($name === '' || $slug === '') {
                throw new SiteError('Ad ve adres (slug) gerekli.');
            }
            if ($slug !== $row->slug) {
                if ($row->status === ContentStatus::Published->value) {
                    throw new SiteError('Yayındaki referansın adresi değiştirilemez; önce arşivleyin.');
                }
                if (DB::table('site_references')->where('slug', $slug)->where('id', '<>', $id)->exists()) {
                    throw new SiteError('Bu adres (slug) başka bir referansta kullanılıyor.');
                }
            }
            $url = trim((string) ($input['website_url'] ?? $row->website_url));
            if ($url !== '' && (filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('#^https?://[^\s"<>]{3,290}$#i', $url) !== 1)) {
                throw new SiteError('Web adresi http(s):// ile başlayan geçerli bir adres olmalı.');
            }
            $capabilities = array_key_exists('capabilities', $input) ? SiteContents::field(['key' => 'capabilities', 'label' => '', 'kind' => 'list', 'max' => 80], $input['capabilities'])
                : (array) json_decode((string) $row->capabilities, true);
            $nameChanged = $name !== $row->name;

            DB::table('site_references')->where('id', $id)->update([
                'name' => $name, 'slug' => $slug, 'website_url' => $url === '' ? null : $url,
                'industry' => SiteContents::line((string) ($input['industry'] ?? $row->industry), 120) ?: null,
                'short_description' => SiteContents::line((string) ($input['short_description'] ?? $row->short_description), 300),
                'body' => SiteContents::text((string) ($input['body'] ?? $row->body), 20000), 'capabilities' => json_encode($capabilities, JSON_UNESCAPED_UNICODE),
                'active' => array_key_exists('active', $input) ? filter_var($input['active'], FILTER_VALIDATE_BOOL) : (bool) $row->active,
                // A different name is not what the client approved.
                ...($nameChanged ? ['client_name_publication_approved' => false, 'name_approved_by' => null, 'name_approved_at' => null] : []),
                'updated_by' => self::by($actor), 'updated_at' => now(),
            ]);
            if (array_key_exists('contents', $input)) {
                $ids = array_values(array_unique(array_filter(array_map('strval', (array) $input['contents']), fn ($c) => Str::isUuid($c))));
                $valid = DB::table('site_contents')->whereIn('id', $ids)->whereIn('type', ['project', 'service', 'solution'])->pluck('id')->all();
                DB::table('site_reference_contents')->where('reference_id', $id)->delete();
                DB::table('site_reference_contents')->insert(array_map(fn ($c) => ['reference_id' => $id, 'content_id' => $c, 'position' => (int) array_search($c, $ids, true)], array_slice($valid, 0, 20)));
            }
            $this->audit->record(AuditAction::SiteReferenceChanged, 'site_reference', $id, array_filter(['name_changed' => $nameChanged ?: null, 'name_approval_reset' => $nameChanged && $row->client_name_publication_approved ?: null]), $actor);
        });
        SiteContents::bump();
    }

    public function setStatus(string $id, ContentStatus $status, Actor $actor): void
    {
        DB::transaction(function () use ($id, $status, $actor) {
            $row = $this->find($id, lock: true);
            if ($status === ContentStatus::Published) {
                if (! $row->client_name_publication_approved) {
                    throw new SiteError('Yayın için önce müşterinin firma adının yayınlanmasına onay verdiği kaydedilmeli.');
                }
                if (trim((string) $row->short_description) === '') {
                    throw new SiteError('Yayın için kısa açıklama gerekli.');
                }
            }
            DB::table('site_references')->where('id', $id)->update(['status' => $status->value, 'published_at' => $status === ContentStatus::Published ? ($row->published_at ?? now()) : $row->published_at,
                'updated_by' => self::by($actor), 'updated_at' => now()]);
            $this->audit->record(match ($status) {
                ContentStatus::Published => AuditAction::SiteReferencePublished,
                ContentStatus::Archived => AuditAction::SiteReferenceArchived,
                ContentStatus::Draft => AuditAction::SiteReferenceChanged,
            }, 'site_reference', $id, ['status' => $status->value], $actor);
        });
        SiteContents::bump();
    }

    /**
     * Only drafts and archived references can be deleted.
     */
    public function delete(string $id, Actor $actor): void
    {
        $row = $this->find($id);
        if ($row->status === ContentStatus::Published->value) {
            throw new SiteError('Yayındaki referans silinemez; önce arşivleyin.');
        }
        DB::table('site_references')->where('id', $id)->delete();
        $this->audit->record(AuditAction::SiteReferenceDeleted, 'site_reference', $id, ['slug' => $row->slug], $actor);
        SiteContents::bump();
    }

    /**
     * Homepage flag and order for many references at once.
     *
     * @param  array<string, array{featured?: mixed, order?: mixed}>  $rows  id => values
     */
    public function arrange(array $rows, Actor $actor): void
    {
        DB::transaction(function () use ($rows, $actor) {
            foreach ($rows as $id => $v) {
                $row = DB::table('site_references')->where('id', $id)->lockForUpdate()->first(['homepage_featured', 'featured_order']);
                if ($row === null) {
                    continue;
                }
                $featured = filter_var($v['featured'] ?? false, FILTER_VALIDATE_BOOL);
                $order = max(-1000, min(1000, (int) ($v['order'] ?? $row->featured_order)));
                if ($featured === (bool) $row->homepage_featured && $order === (int) $row->featured_order) {
                    continue;
                }
                DB::table('site_references')->where('id', $id)->update(['homepage_featured' => $featured, 'featured_order' => $order, 'updated_by' => self::by($actor), 'updated_at' => now()]);
                $this->audit->record(AuditAction::SiteReferenceFeaturedChanged, 'site_reference', (string) $id, ['featured' => $featured, 'order' => $order], $actor);
            }
        });
        SiteContents::bump();
    }

    /**
     * Records or withdraws the client's approval to publish the name / use the logo.
     */
    public function approve(string $id, string $what, bool $approved, string $note, Actor $actor): void
    {
        if (! in_array($what, ['name', 'logo'], true)) {
            throw new SiteError('Geçersiz onay türü.');
        }
        if ($approved && trim($note) === '') {
            throw new SiteError('Onayın nasıl alındığını yazın.');
        }
        DB::transaction(function () use ($id, $what, $approved, $note, $actor) {
            $row = $this->find($id, lock: true);
            if ($what === 'logo' && $approved && $row->logo_media_id === null) {
                throw new SiteError('Önce logo yükleyin.');
            }
            $col = $what === 'name' ? ['client_name_publication_approved', 'name_approved_by', 'name_approved_at'] : ['logo_use_approved', 'logo_approved_by', 'logo_approved_at'];
            DB::table('site_references')->where('id', $id)->update([$col[0] => $approved, $col[1] => $approved ? self::by($actor) : null, $col[2] => $approved ? now() : null,
                'approval_note' => trim($note) === '' ? $row->approval_note : SiteContents::line($note, 255), 'updated_by' => self::by($actor), 'updated_at' => now()]);
            $this->audit->record($what === 'name' ? AuditAction::SiteReferenceNameApproval : AuditAction::SiteReferenceLogoApproval, 'site_reference', $id,
                ['approved' => $approved, 'note' => SiteContents::line($note, 255)], $actor);
        });
        SiteContents::bump();
    }

    /**
     * Uploads (through the hardened SiteMedia pipeline) or removes a logo.
     * A new light logo needs a new logo approval.
     */
    public function setLogo(string $id, string $variant, ?UploadedFile $file, string $alt, Actor $actor): void
    {
        if (! in_array($variant, ['light', 'dark'], true)) {
            throw new SiteError('Geçersiz logo türü.');
        }
        $col = $variant === 'light' ? 'logo_media_id' : 'logo_dark_media_id';
        $previous = $this->find($id)->{$col};
        // The new file is stored (and its row written) before anything points at it.
        $mediaId = $file === null ? null : $this->media->upload($file, $alt, $actor);
        DB::table('site_references')->where('id', $id)->update([$col => $mediaId,
            ...($variant === 'light' ? ['logo_use_approved' => false, 'logo_approved_by' => null, 'logo_approved_at' => null] : []), 'updated_by' => self::by($actor), 'updated_at' => now()]);
        $this->audit->record(AuditAction::SiteReferenceLogoChanged, 'site_reference', $id, ['variant' => $variant, 'removed' => $mediaId === null], $actor);
        SiteContents::bump();
        // Only now drop the old logo, if nothing else uses it (no orphans).
        if ($previous !== null && $previous !== $mediaId) {
            $this->media->delete((string) $previous, $actor);
        }
    }

    public function find(string $id, bool $lock = false): object
    {
        $q = DB::table('site_references')->where('id', $id);

        return ($lock ? $q->lockForUpdate() : $q)->first() ?? throw new SiteError('Referans bulunamadı.');
    }

    private static function by(Actor $actor): string
    {
        return mb_substr($actor->type.($actor->id !== null ? ':'.$actor->id : ''), 0, 64);
    }
}
