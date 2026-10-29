<?php

namespace App\Site;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Website images. An upload is untrusted:
 *
 * - size limit; the type is detected from the bytes (JPEG, PNG, WebP only;
 *   no SVG, GIF or anything that can carry script);
 * - pixel limit before decoding (decompression bombs);
 * - decoded and re-encoded with GD into WebP and JPEG variants: metadata
 *   (EXIF, location) and any appended payload are dropped;
 * - served only from /media/{id}/{width}.{webp|jpg} on our own origin.
 */
final class SiteMedia
{
    private const TYPES = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];

    public function __construct(private readonly AuditLogger $audit, private readonly SiteSettings $settings) {}

    public function upload(UploadedFile $file, string $alt, Actor $actor): string
    {
        $disk = $this->settings->mediaDisk();
        if (app()->isProduction() && ! SiteSettings::persistentDisk($disk)) {
            throw new SiteError('Görsel yükleme kapalı: production\'da kalıcı bir object storage diski seçilmeli (Site Ayarları).');
        }
        $alt = SiteContents::line($alt, 200);
        if ($alt === '') {
            throw new SiteError('Görsel için alternatif metin (alt) gerekli.');
        }
        $path = (string) $file->getRealPath();
        if (! $file->isValid() || $file->getSize() === false || $file->getSize() > (int) config('site.media_max_bytes')) {
            throw new SiteError('Görsel en fazla '.(int) (config('site.media_max_bytes') / 1048576).' MB olabilir.');
        }
        $info = @getimagesize($path);
        if ($info === false || ! isset(self::TYPES[$info[2]]) || (new \finfo(FILEINFO_MIME_TYPE))->file($path) !== self::TYPES[$info[2]]) {
            throw new SiteError('Yalnızca JPEG, PNG veya WebP görsel yüklenebilir.');
        }
        [$width, $height] = $info;
        if ($width < 16 || $height < 16 || $width * $height > (int) config('site.media_max_pixels')) {
            throw new SiteError('Görsel boyutu desteklenmiyor.');
        }
        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            default => @imagecreatefromwebp($path),
        };
        if (! $image instanceof GdImage) {
            throw new SiteError('Görsel okunamadı.');
        }

        $sha = hash_file('sha256', $path);
        $existing = DB::table('site_media')->where('sha256', $sha)->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid7();
        $variants = ['webp' => [], 'jpg' => []];
        foreach (array_unique([...array_filter((array) config('site.media_widths'), fn ($w) => $w < $width), min($width, max((array) config('site.media_widths')))]) as $w) {
            $scaled = $w === $width ? $image : imagescale($image, (int) $w);
            if (! $scaled instanceof GdImage) {
                continue;
            }
            foreach (['webp' => fn ($img) => imagewebp($img, null, 82), 'jpg' => fn ($img) => imagejpeg($this->flatten($img), null, 84)] as $ext => $encode) {
                ob_start();
                $encode($scaled);
                $bytes = (string) ob_get_clean();
                $key = "site-media/{$id}/{$w}.{$ext}";
                Storage::disk($disk)->put($key, $bytes);
                $variants[$ext][(string) $w] = $key;
            }
            if ($scaled !== $image) {
                imagedestroy($scaled);
            }
        }
        imagedestroy($image);
        DB::table('site_media')->insert(['id' => $id, 'sha256' => $sha, 'disk' => $disk, 'alt' => $alt, 'width' => $width, 'height' => $height,
            'variants' => json_encode($variants), 'created_by' => mb_substr($actor->type.':'.$actor->id, 0, 64), 'created_at' => now()]);
        $this->audit->record(AuditAction::SiteMediaUploaded, 'site_media', $id, ['width' => $width, 'height' => $height], $actor);

        return $id;
    }

    /**
     * @return array{id: string, alt: string, width: int, height: int, webp: array<string, string>, jpg: array<string, string>}|null
     */
    public static function describe(?string $id): ?array
    {
        $row = $id === null ? null : DB::table('site_media')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $variants = (array) json_decode((string) $row->variants, true);
        $urls = fn (string $ext) => collect((array) ($variants[$ext] ?? []))->mapWithKeys(fn ($p, $w) => [(string) $w => "/media/{$row->id}/{$w}.{$ext}"])->all();

        return ['id' => (string) $row->id, 'alt' => (string) $row->alt, 'width' => (int) $row->width, 'height' => (int) $row->height, 'webp' => $urls('webp'), 'jpg' => $urls('jpg')];
    }

    /**
     * JPEG has no alpha: paint transparent images on white.
     */
    private function flatten(GdImage $image): GdImage
    {
        $out = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($out, 0, 0, (int) imagecolorallocate($out, 255, 255, 255));
        imagecopy($out, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $out;
    }
}
