<?php

namespace App\Site;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service and article categories (panel-managed, never hard-coded).
 */
final class SiteCategories
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name?: mixed, slug?: mixed, description?: mixed, sort_order?: mixed, active?: mixed}  $input
     */
    public function save(string $kind, array $input, Actor $actor, ?string $id = null): string
    {
        if (! in_array($kind, ['service', 'article'], true)) {
            throw new SiteError('Geçersiz kategori türü.');
        }
        $name = SiteContents::line((string) ($input['name'] ?? ''), 120);
        $slug = SiteContents::slug((string) (($input['slug'] ?? '') ?: $name));
        if ($name === '' || $slug === '') {
            throw new SiteError('Kategori adı gerekli.');
        }
        if (DB::table('site_categories')->where('kind', $kind)->where('slug', $slug)->when($id !== null, fn ($q) => $q->where('id', '<>', $id))->exists()) {
            throw new SiteError('Bu kategori adresi zaten kullanılıyor.');
        }
        $values = ['name' => $name, 'slug' => $slug, 'description' => SiteContents::text((string) ($input['description'] ?? ''), 1000) ?: null,
            'sort_order' => (int) ($input['sort_order'] ?? 0), 'active' => filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOL), 'updated_at' => now()];
        if ($id === null) {
            $id = (string) Str::uuid7();
            DB::table('site_categories')->insert($values + ['id' => $id, 'kind' => $kind, 'created_at' => now()]);
        } elseif (DB::table('site_categories')->where('id', $id)->where('kind', $kind)->update($values) !== 1) {
            throw new SiteError('Kategori bulunamadı.');
        }
        $this->audit->record(AuditAction::SiteCategoryChanged, 'site_category', $id, ['kind' => $kind, 'slug' => $slug], $actor);
        SiteContents::bump();

        return $id;
    }
}
