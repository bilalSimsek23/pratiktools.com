<?php

namespace App\Site;

use Illuminate\Support\Str;

/**
 * CMS text to HTML. Bodies are Markdown; raw HTML in them is stripped
 * (never rendered), javascript:/data: links are dropped, and images may
 * only point at our own /media/ files. No Blade, PHP or script can reach
 * a page through content.
 */
final class SiteMarkdown
{
    public static function render(string $markdown): string
    {
        $html = Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 20]);
        // Only our media; external images would also break the CSP.
        $html = (string) preg_replace('#<img\b(?![^>]*\bsrc="/media/)[^>]*>#i', '', $html);
        // Outbound links: no opener, no referrer.
        $html = (string) preg_replace_callback('#<a href="(https?://[^"]+)"#i', function (array $m) {
            return str_starts_with($m[1], rtrim((string) config('app.url'), '/'))
                ? $m[0]
                : '<a href="'.$m[1].'" rel="noopener nofollow" target="_blank"';
        }, $html);

        return $html;
    }

    /**
     * Plain text of a Markdown body (meta descriptions, schema).
     */
    public static function plain(string $markdown, int $limit = 160): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags(self::render($markdown))));

        return Str::limit($text, $limit, '…');
    }
}
