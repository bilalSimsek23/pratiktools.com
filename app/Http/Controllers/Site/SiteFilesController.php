<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Site\SiteEvents;
use App\Site\SiteQuery;
use App\Site\SiteSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * robots.txt, sitemap.xml, website images and the event beacon.
 */
final class SiteFilesController extends Controller
{
    /**
     * Search engines and OAI-SearchBot (ChatGPT search) may crawl the public
     * site; the panel, API and technical endpoints are disallowed for all.
     * GPTBot (model training) follows its own site setting.
     */
    public function robots(SiteSettings $settings): Response
    {
        $disallow = implode("\n", array_map(fn ($p) => 'Disallow: '.$p, (array) config('site.disallow_paths')));
        $gptbot = $settings->get('crawlers')['gptbot'] === 'allow' ? "Allow: /\n{$disallow}" : 'Disallow: /';
        $body = "User-agent: *\nAllow: /\n{$disallow}\n\nUser-agent: OAI-SearchBot\nAllow: /\n{$disallow}\n\nUser-agent: GPTBot\n{$gptbot}\n\n"
            .'Sitemap: '.rtrim((string) config('app.url'), '/')."/sitemap.xml\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function sitemap(SiteQuery $site): Response
    {
        $base = rtrim((string) config('app.url'), '/');
        $urls = [['loc' => '/', 'lastmod' => null], ...array_map(fn ($p) => ['loc' => $p, 'lastmod' => null], ['/hizmetler', '/cozumler', '/projeler', '/referanslar', '/blog']),
            ...array_values(array_filter($site->sitemap(), fn ($u) => $u['loc'] !== '/'))];
        $seen = [];
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $u) {
            if (isset($seen[$u['loc']])) {
                continue;
            }
            $seen[$u['loc']] = true;
            $xml .= '  <url><loc>'.htmlspecialchars($base.$u['loc'], ENT_XML1).'</loc>'.($u['lastmod'] ? '<lastmod>'.$u['lastmod'].'</lastmod>' : '')."</url>\n";
        }

        return response($xml.'</urlset>'."\n", 200, ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    /**
     * Website images from a private bucket: read by the app with the disk's
     * own credentials and streamed; the bucket is never public. URLs are
     * content-addressed (a replaced image has a new id), so they are cached
     * as immutable. Storage problems answer 503 without details.
     */
    public function media(string $id, string $width, string $ext): BaseResponse
    {
        $row = DB::table('site_media')->where('id', $id)->first(['disk', 'variants']) ?? abort(404);
        $path = ((array) json_decode((string) $row->variants, true))[$ext][$width] ?? abort(404);
        try {
            $stream = Storage::disk((string) $row->disk)->readStream($path);
        } catch (\Throwable $e) {
            report($e);
            $stream = false;
        }
        if (! is_resource($stream)) {
            return response('', 503, ['Cache-Control' => 'no-store', 'Retry-After' => '60']);
        }

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, 200, ['Content-Type' => $ext === 'webp' ? 'image/webp' : 'image/jpeg', 'Cache-Control' => 'public, max-age=31536000, immutable', 'Content-Disposition' => 'inline']);
    }

    public function event(Request $request, SiteEvents $events): Response
    {
        $data = strlen((string) $request->getContent()) > 512 ? null : json_decode((string) $request->getContent(), true);
        if (is_array($data) && is_string($data['e'] ?? null) && is_string($data['p'] ?? null) && $data['e'] !== 'lead_submit') {
            $events->record($data['e'], $data['p']);
        }

        return response('', 204, ['Cache-Control' => 'no-store']);
    }
}
