<?php

namespace App\Site;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writing website content (panel). Every change is audited and bumps the
 * site cache version.
 *
 * - draft → published → archived (→ draft). Only published content is
 *   public; publishing is a separate permission (controller).
 * - Slugs are unique per type and cannot change once published (URLs).
 * - Text fields are stored as plain text (escaped when rendered), bodies
 *   as Markdown (rendered without raw HTML).
 * - Projects: the real client name stays internal unless "client named" is
 *   set AND a client approval is recorded; publishing refuses otherwise.
 */
final class SiteContents
{
    public function __construct(private readonly AuditLogger $audit) {}

    public static function version(): string
    {
        return (string) Cache::get('site:version', '0');
    }

    public static function bump(): void
    {
        Cache::forever('site:version', (string) Str::uuid7());
    }

    public function create(ContentType $type, string $title, Actor $actor, ?string $slug = null): string
    {
        $title = self::line($title, 200);
        if ($title === '') {
            throw new SiteError('Başlık gerekli.');
        }
        $slug = $this->uniqueSlug($type, $slug ?? $title);
        $id = (string) Str::uuid7();
        DB::table('site_contents')->insert(['id' => $id, 'type' => $type->value, 'slug' => $slug, 'title' => $title, 'data' => '{}', 'status' => ContentStatus::Draft->value,
            'created_by' => self::by($actor), 'updated_by' => self::by($actor), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record(AuditAction::SiteContentCreated, 'site_content', $id, ['type' => $type->value, 'slug' => $slug], $actor);
        self::bump();

        return $id;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(string $id, array $input, Actor $actor): void
    {
        DB::transaction(function () use ($id, $input, $actor) {
            $row = $this->find($id, lock: true);
            $type = ContentType::from($row->type);
            $title = self::line((string) ($input['title'] ?? $row->title), 200);
            if ($title === '') {
                throw new SiteError('Başlık gerekli.');
            }
            $slug = self::slug((string) ($input['slug'] ?? $row->slug));
            if ($slug !== $row->slug) {
                if ($row->status === ContentStatus::Published->value) {
                    throw new SiteError('Yayındaki içeriğin adresi değiştirilemez; önce arşivleyin.');
                }
                if ($slug === '' || DB::table('site_contents')->where('type', $type->value)->where('slug', $slug)->where('id', '<>', $id)->exists()) {
                    throw new SiteError('Bu adres (slug) bu içerik türünde zaten kullanılıyor.');
                }
            }
            $data = (array) json_decode((string) $row->data, true);
            $before = $data;
            foreach ($type->fields() as $f) {
                if (array_key_exists($f['key'], $input) || $f['kind'] === 'bool') {
                    $data[$f['key']] = self::field($f, $input[$f['key']] ?? null);
                }
            }
            // A changed client name or naming decision needs a new approval.
            if ($type === ContentType::Project && (($before['client_name'] ?? null) !== ($data['client_name'] ?? null) || ! ($data['client_named'] ?? false))) {
                unset($data['client_approval']);
            }
            $category = $input['category_id'] ?? $row->category_id;
            if ($category !== null && $category !== '' && ! DB::table('site_categories')->where('id', $category)->where('kind', (string) $type->categoryKind())->exists()) {
                throw new SiteError('Geçersiz kategori.');
            }
            $image = $input['social_image_id'] ?? $row->social_image_id;
            if ($image !== null && $image !== '' && ! DB::table('site_media')->where('id', $image)->exists()) {
                throw new SiteError('Geçersiz görsel.');
            }

            DB::table('site_contents')->where('id', $id)->update([
                'title' => $title, 'slug' => $slug, 'excerpt' => self::text((string) ($input['excerpt'] ?? $row->excerpt), 600),
                'body' => self::text((string) ($input['body'] ?? $row->body), 60000), 'data' => json_encode($data),
                'category_id' => $category === '' ? null : $category, 'social_image_id' => $image === '' ? null : $image,
                'seo_title' => self::line((string) ($input['seo_title'] ?? $row->seo_title), 70) ?: null,
                'seo_description' => self::line((string) ($input['seo_description'] ?? $row->seo_description), 170) ?: null,
                'featured' => (bool) ($input['featured'] ?? $row->featured), 'sort_order' => (int) ($input['sort_order'] ?? $row->sort_order),
                'updated_by' => self::by($actor), 'updated_at' => now(),
            ]);
            if (array_key_exists('related', $input)) {
                $this->relate($id, (array) $input['related']);
            }
            $this->audit->record(AuditAction::SiteContentChanged, 'site_content', $id, ['type' => $type->value], $actor);
        });
        self::bump();
    }

    public function publish(string $id, Actor $actor): void
    {
        DB::transaction(function () use ($id, $actor) {
            $row = $this->find($id, lock: true);
            $data = (array) json_decode((string) $row->data, true);
            $missing = array_keys(array_filter(['title' => $row->title, 'excerpt' => $row->excerpt], fn ($v) => trim((string) $v) === ''));
            if ($missing !== []) {
                throw new SiteError('Yayın için gerekli alan eksik: '.implode(', ', $missing).'.');
            }
            if ($row->type === ContentType::Project->value && ($data['client_named'] ?? false) && ! isset($data['client_approval'])) {
                throw new SiteError('Müşteri adı yayınlanacaksa önce müşteri onayı kaydedilmeli.');
            }
            DB::table('site_contents')->where('id', $id)->update(['status' => ContentStatus::Published->value, 'published_at' => $row->published_at ?? now(),
                'published_by' => self::by($actor), 'updated_at' => now()]);
            $this->audit->record(AuditAction::SiteContentPublished, 'site_content', $id, ['type' => $row->type, 'slug' => $row->slug], $actor);
        });
        self::bump();
    }

    public function setStatus(string $id, ContentStatus $status, Actor $actor): void
    {
        if ($status === ContentStatus::Published) {
            $this->publish($id, $actor);

            return;
        }
        $row = $this->find($id);
        DB::table('site_contents')->where('id', $id)->update(['status' => $status->value, 'updated_at' => now(), 'updated_by' => self::by($actor)]);
        $this->audit->record($status === ContentStatus::Archived ? AuditAction::SiteContentArchived : AuditAction::SiteContentChanged, 'site_content', $id,
            ['type' => $row->type, 'status' => $status->value], $actor);
        self::bump();
    }

    /**
     * Records that the client agreed to be named publicly (project).
     */
    public function approveClient(string $id, string $note, Actor $actor): void
    {
        if (trim($note) === '') {
            throw new SiteError('Onayın nasıl alındığını yazın.');
        }
        DB::transaction(function () use ($id, $note, $actor) {
            $row = $this->find($id, lock: true);
            $data = (array) json_decode((string) $row->data, true);
            if ($row->type !== ContentType::Project->value || ! ($data['client_named'] ?? false) || trim((string) ($data['client_name'] ?? '')) === '') {
                throw new SiteError('Önce gerçek müşteri adını girip "Müşteri adı yayınlansın" seçeneğini işaretleyin.');
            }
            $data['client_approval'] = ['by' => self::by($actor), 'at' => now()->toIso8601String(), 'note' => self::line($note, 255)];
            DB::table('site_contents')->where('id', $id)->update(['data' => json_encode($data), 'updated_at' => now()]);
            $this->audit->record(AuditAction::SiteClientApproved, 'site_content', $id, ['note' => self::line($note, 255)], $actor);
        });
        self::bump();
    }

    public function find(string $id, bool $lock = false): object
    {
        $q = DB::table('site_contents')->where('id', $id);

        return ($lock ? $q->lockForUpdate() : $q)->first() ?? throw new SiteError('İçerik bulunamadı.');
    }

    /**
     * @param  list<mixed>  $ids
     */
    private function relate(string $id, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), fn ($r) => Str::isUuid($r) && $r !== $id)));
        DB::table('site_content_relations')->where('content_id', $id)->delete();
        $valid = DB::table('site_contents')->whereIn('id', $ids)->pluck('id')->all();
        DB::table('site_content_relations')->insert(array_map(fn ($r) => ['content_id' => $id, 'related_id' => $r], array_slice($valid, 0, 30)));
    }

    private function uniqueSlug(ContentType $type, string $source): string
    {
        $base = self::slug($source) ?: 'icerik';
        $slug = $base;
        for ($i = 2; DB::table('site_contents')->where('type', $type->value)->where('slug', $slug)->exists(); $i++) {
            $slug = mb_substr($base, 0, 130).'-'.$i;
        }

        return $slug;
    }

    public static function slug(string $value): string
    {
        return mb_substr(trim(Str::slug($value, '-', 'tr'), '-'), 0, 140);
    }

    /**
     * @param  array{key: string, label: string, kind: string, max?: int, options?: array<string, string>}  $f
     */
    public static function field(array $f, mixed $value): mixed
    {
        $max = $f['max'] ?? 300;
        $lines = fn ($v) => array_values(array_filter(array_map(fn ($l) => self::line($l, $max), preg_split('/\R/u', (string) $v) ?: []), fn ($l) => $l !== ''));

        return match ($f['kind']) {
            'text' => self::line((string) $value, $max),
            'textarea' => self::text((string) $value, $max),
            'list' => array_slice($lines($value), 0, 20),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL),
            'select' => array_key_exists((string) $value, $f['options'] ?? []) ? (string) $value : array_key_first($f['options'] ?? []),
            'steps' => array_slice(array_map(function ($l) {
                [$title, $text] = array_pad(array_map('trim', explode('|', $l, 2)), 2, '');

                return ['title' => $title, 'text' => $text];
            }, $lines($value)), 0, 8),
            'faq' => array_slice(array_values(array_filter(array_map(function ($block) use ($max) {
                $rows = array_values(array_filter(array_map('trim', preg_split('/\R/u', $block) ?: []), fn ($l) => $l !== ''));

                return count($rows) < 2 ? null : ['q' => self::line($rows[0], 300), 'a' => self::text(implode("\n", array_slice($rows, 1)), $max)];
            }, preg_split('/\R\s*\R/u', trim((string) $value)) ?: []))), 0, 15),
            default => null,
        };
    }

    /**
     * Turns stored structured data back into form text.
     *
     * @param  array{kind: string}  $f
     */
    public static function formValue(array $f, mixed $value): string
    {
        return match ($f['kind']) {
            'list' => implode("\n", (array) $value),
            'steps' => implode("\n", array_map(fn ($s) => ($s['title'] ?? '').' | '.($s['text'] ?? ''), (array) $value)),
            'faq' => implode("\n\n", array_map(fn ($q) => ($q['q'] ?? '')."\n".($q['a'] ?? ''), (array) $value)),
            'bool' => $value ? '1' : '',
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    public static function line(string $value, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)), 0, $max);
    }

    public static function text(string $value, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace("\r\n", "\n", $value))), 0, $max);
    }

    private static function by(Actor $actor): string
    {
        return mb_substr($actor->type.($actor->id !== null ? ':'.$actor->id : ''), 0, 64);
    }
}
