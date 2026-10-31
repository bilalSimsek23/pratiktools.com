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
 * - served only from /media/{id}/{width}.{webp|jpg|png} on our own origin.
 *
 * Kinds: "image" (WebP + JPEG variants) and "icon" (brand favicon / Apple
 * Touch Icon: square, re-encoded to PNG at fixed icon sizes).
 */
final class SiteMedia
{
    private const TYPES = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];

    /** Icon sizes written for kind "icon" (only those not larger than the source). */
    public const ICON_SIZES = [32, 48, 180, 192, 512];

    public function __construct(private readonly AuditLogger $audit, private readonly SiteSettings $settings) {}

    /**
     * @param  string  $kind  image | icon (square, at least $minSide pixels)
     * @param  int  $minSide  smallest accepted width and height
     */
    public function upload(UploadedFile $file, string $alt, Actor $actor, string $kind = 'image', int $minSide = 16): string
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
        $minSide = $kind === 'icon' ? max($minSide, min(self::ICON_SIZES)) : $minSide;
        if ($kind === 'icon' && $width !== $height) {
            throw new SiteError("İkon kare olmalı (yüklenen: {$width}×{$height}).");
        }
        if ($width < $minSide || $height < $minSide) {
            throw new SiteError("Görsel en az {$minSide}×{$minSide} piksel olmalı (yüklenen: {$width}×{$height}).");
        }
        // The same file as an icon and as an image are different media items.
        $sha = $kind === 'icon' ? hash('sha256', 'icon:'.hash_file('sha256', $path)) : hash_file('sha256', $path);
        $existing = DB::table('site_media')->where('sha256', $sha)->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }
        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            default => @imagecreatefromwebp($path),
        };
        if (! $image instanceof GdImage) {
            throw new SiteError('Görsel okunamadı.');
        }

        $id = (string) Str::uuid7();
        $variants = $kind === 'icon' ? ['png' => []] : ['webp' => [], 'jpg' => []];
        $written = [];
        try {
            $storage = Storage::disk($disk);
            $put = function (string $key, string $bytes) use ($storage, &$written) {
                if ($storage->put($key, $bytes) !== true) {
                    throw new \RuntimeException('write refused');
                }
                $written[] = $key;
            };
            if ($kind === 'icon') {
                imagealphablending($image, false);
                imagesavealpha($image, true);
                foreach (array_filter(self::ICON_SIZES, fn ($s) => $s <= $width) as $s) {
                    $scaled = $s === $width ? $image : imagescale($image, $s, $s);
                    if (! $scaled instanceof GdImage) {
                        continue;
                    }
                    imagesavealpha($scaled, true);
                    ob_start();
                    imagepng($scaled, null, 9);
                    $put($key = "site-media/{$id}/{$s}.png", (string) ob_get_clean());
                    $variants['png'][(string) $s] = $key;
                    if ($scaled !== $image) {
                        imagedestroy($scaled);
                    }
                }
            }
            foreach ($kind === 'icon' ? [] : array_unique([...array_filter((array) config('site.media_widths'), fn ($w) => $w < $width), min($width, max((array) config('site.media_widths')))]) as $w) {
                $scaled = $w === $width ? $image : imagescale($image, (int) $w);
                if (! $scaled instanceof GdImage) {
                    continue;
                }
                foreach (['webp' => fn ($img) => imagewebp($img, null, 82), 'jpg' => fn ($img) => imagejpeg($this->flatten($img), null, 84)] as $ext => $encode) {
                    ob_start();
                    $encode($scaled);
                    $bytes = (string) ob_get_clean();
                    // Content-addressed by the media id: a replaced image gets a new id and URL.
                    $put($key = "site-media/{$id}/{$w}.{$ext}", $bytes);
                    $variants[$ext][(string) $w] = $key;
                }
                if ($scaled !== $image) {
                    imagedestroy($scaled);
                }
            }
            DB::table('site_media')->insert(['id' => $id, 'sha256' => $sha, 'disk' => $disk, 'alt' => $alt, 'width' => $width, 'height' => $height, 'kind' => $kind,
                'variants' => json_encode($variants), 'created_by' => mb_substr($actor->type.':'.$actor->id, 0, 64), 'created_at' => now()]);
        } catch (\Throwable $e) {
            // All or nothing: no row without files, no files without a row.
            $this->forget($disk, $written);
            report($e);

            throw new SiteError('Görsel depolamaya yazılamadı. Depolama şu anda kullanılamıyor olabilir; lütfen daha sonra tekrar deneyin.');
        } finally {
            imagedestroy($image);
        }
        $this->audit->record(AuditAction::SiteMediaUploaded, 'site_media', $id, ['width' => $width, 'height' => $height, 'disk' => $disk, 'kind' => $kind], $actor);

        return $id;
    }

    /**
     * Where a media item is used (content social images, reference logos,
     * brand logos / icons / default share image).
     */
    public static function usage(string $id): int
    {
        $brand = (array) json_decode((string) DB::table('site_settings')->where('key', 'brand')->value('value'), true);

        return DB::table('site_contents')->where('social_image_id', $id)->count()
            + DB::table('site_references')->where(fn ($q) => $q->where('logo_media_id', $id)->orWhere('logo_dark_media_id', $id))->count()
            + count(array_keys(array_intersect_key($brand, SiteBrand::IMAGES), $id, true));
    }

    /**
     * Deletes a media item that nothing uses: the row first (the site never
     * points at a missing file), then its files. Returns false if in use.
     */
    public function delete(string $id, Actor $actor): bool
    {
        $row = DB::table('site_media')->where('id', $id)->first(['disk', 'variants']);
        if ($row === null || self::usage($id) > 0) {
            return false;
        }
        DB::table('site_media')->where('id', $id)->delete();
        $this->forget((string) $row->disk, array_merge(...array_map('array_values', array_values((array) json_decode((string) $row->variants, true)))));
        $this->audit->record(AuditAction::SiteMediaDeleted, 'site_media', $id, [], $actor);

        return true;
    }

    /**
     * Best effort: a file left behind is reported, never fatal.
     *
     * @param  list<string>  $keys
     */
    private function forget(string $disk, array $keys): void
    {
        if ($keys === []) {
            return;
        }
        try {
            Storage::disk($disk)->delete($keys);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array{id: string, kind: string, alt: string, width: int, height: int, webp: array<string, string>, jpg: array<string, string>, png: array<string, string>}|null
     */
    public static function describe(?string $id): ?array
    {
        $row = $id === null ? null : DB::table('site_media')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $variants = (array) json_decode((string) $row->variants, true);
        $urls = fn (string $ext) => collect((array) ($variants[$ext] ?? []))->mapWithKeys(fn ($p, $w) => [(string) $w => "/media/{$row->id}/{$w}.{$ext}"])->all();

        return ['id' => (string) $row->id, 'kind' => (string) ($row->kind ?? 'image'), 'alt' => (string) $row->alt, 'width' => (int) $row->width, 'height' => (int) $row->height,
            'webp' => $urls('webp'), 'jpg' => $urls('jpg'), 'png' => $urls('png')];
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
