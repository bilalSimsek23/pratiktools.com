<?php

namespace App\Site;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reading the public website: published content only, as plain arrays,
 * cached per site version (any panel change bumps it). Never touches a
 * tenant table.
 */
final class SiteQuery
{
    public const PAGE_PATHS = ['home' => '/', 'hakkimizda' => '/hakkimizda', 'iletisim' => '/iletisim'];

    public static function url(string $type, string $slug): string
    {
        $type = ContentType::from($type);

        return $type === ContentType::Page ? (self::PAGE_PATHS[$slug] ?? '/'.$slug) : '/'.$type->routePrefix().'/'.$slug;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function published(ContentType $type, ?string $categoryId = null, ?int $limit = null, bool $featuredOnly = false): array
    {
        return $this->cached("list:{$type->value}:{$categoryId}:{$limit}:".(int) $featuredOnly, fn () => DB::table('site_contents as c')
            ->leftJoin('site_categories as k', 'k.id', '=', 'c.category_id')
            ->where('c.type', $type->value)->where('c.status', ContentStatus::Published->value)
            ->when($categoryId !== null, fn ($q) => $q->where('c.category_id', $categoryId))
            ->when($featuredOnly, fn ($q) => $q->where('c.featured', true))
            ->orderBy('c.sort_order')->orderByDesc('c.published_at')->when($limit !== null, fn ($q) => $q->limit((int) $limit))
            ->get(['c.id', 'c.type', 'c.slug', 'c.title', 'c.excerpt', 'c.data', 'c.published_at', 'c.updated_at', 'c.social_image_id', 'c.category_id', 'k.name as category_name', 'k.slug as category_slug'])
            ->map(fn ($r) => self::row($r))->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(ContentType $type, string $slug): ?array
    {
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
            return null;
        }

        return $this->cached("one:{$type->value}:{$slug}", function () use ($type, $slug) {
            $r = DB::table('site_contents as c')->leftJoin('site_categories as k', 'k.id', '=', 'c.category_id')
                ->where('c.type', $type->value)->where('c.slug', $slug)->where('c.status', ContentStatus::Published->value)
                ->first(['c.*', 'k.name as category_name', 'k.slug as category_slug']);
            if ($r === null) {
                return null;
            }
            $item = self::row($r) + ['body' => (string) $r->body, 'seo_title' => $r->seo_title, 'seo_description' => $r->seo_description];
            $item['related'] = DB::table('site_content_relations as rel')->join('site_contents as c', 'c.id', '=', 'rel.related_id')
                ->where('c.status', ContentStatus::Published->value)
                ->where(fn ($q) => $q->where('rel.content_id', $r->id))
                ->orderBy('c.type')->orderBy('c.sort_order')->get(['c.type', 'c.slug', 'c.title', 'c.excerpt'])
                ->map(fn ($x) => ['type' => $x->type, 'title' => $x->title, 'excerpt' => $x->excerpt, 'url' => self::url($x->type, $x->slug)])->all();
            // Content that points at this one (e.g. projects of a service).
            $item['referenced_by'] = DB::table('site_content_relations as rel')->join('site_contents as c', 'c.id', '=', 'rel.content_id')
                ->where('rel.related_id', $r->id)->where('c.status', ContentStatus::Published->value)
                ->orderBy('c.type')->orderBy('c.sort_order')->get(['c.type', 'c.slug', 'c.title', 'c.excerpt'])
                ->map(fn ($x) => ['type' => $x->type, 'title' => $x->title, 'excerpt' => $x->excerpt, 'url' => self::url($x->type, $x->slug)])->all();

            return $item;
        });
    }

    /**
     * Any status, uncached: panel preview only (never a public route).
     *
     * @return array<string, mixed>|null
     */
    public function preview(string $id): ?array
    {
        $r = DB::table('site_contents as c')->leftJoin('site_categories as k', 'k.id', '=', 'c.category_id')->where('c.id', $id)
            ->first(['c.*', 'k.name as category_name', 'k.slug as category_slug']);

        return $r === null ? null : self::row($r) + ['body' => (string) $r->body, 'seo_title' => $r->seo_title, 'seo_description' => $r->seo_description,
            'related' => [], 'referenced_by' => [], 'status' => (string) $r->status];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function categories(string $kind): array
    {
        return $this->cached("cats:{$kind}", fn () => DB::table('site_categories')->where('kind', $kind)->where('active', true)
            ->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'slug', 'description'])->map(fn ($c) => (array) $c)->all());
    }

    /**
     * Every published URL with its last change (sitemap).
     *
     * @return list<array{loc: string, lastmod: ?string}>
     */
    public function sitemap(): array
    {
        return $this->cached('sitemap', fn () => DB::table('site_contents')->where('status', ContentStatus::Published->value)
            ->orderBy('type')->orderBy('sort_order')->get(['type', 'slug', 'updated_at'])
            ->map(fn ($r) => ['loc' => self::url($r->type, $r->slug), 'lastmod' => $r->updated_at === null ? null : substr((string) $r->updated_at, 0, 10)])->all());
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(object $r): array
    {
        return ['id' => (string) $r->id, 'type' => (string) $r->type, 'slug' => (string) $r->slug, 'title' => (string) $r->title, 'excerpt' => (string) $r->excerpt,
            'data' => (array) json_decode((string) $r->data, true), 'url' => self::url((string) $r->type, (string) $r->slug),
            'published_at' => $r->published_at, 'updated_at' => $r->updated_at, 'image' => SiteMedia::describe($r->social_image_id),
            'category' => $r->category_name === null ? null : ['name' => (string) $r->category_name, 'slug' => (string) $r->category_slug, 'id' => (string) $r->category_id]];
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $fn
     * @return T
     */
    private function cached(string $key, \Closure $fn): mixed
    {
        return Cache::remember('site:q:'.SiteContents::version().':'.$key, 600, $fn);
    }
}
