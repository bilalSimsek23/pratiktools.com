<?php

namespace App\Site;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Round trip on the website media disk: write a unique object, check it
 * exists, read it back and compare its hash, delete it, check it is gone.
 * Never prints configuration or credentials; leaves nothing behind.
 */
final class SiteStorageCheck
{
    public function __construct(private readonly SiteSettings $settings, private readonly AuditLogger $audit) {}

    /**
     * @return array{disk: string, driver: ?string, persistent: bool, passed: bool, steps: array<string, bool>, error: ?string}
     */
    public function run(Actor $actor, ?string $disk = null): array
    {
        $disk ??= $this->settings->mediaDisk();
        $steps = array_fill_keys(['configured', 'write', 'exists', 'read_hash', 'delete', 'gone'], false);
        $error = null;
        $key = 'site-media-healthcheck/'.Str::uuid7().'.txt';
        $body = 'pratiktools-storage-check '.bin2hex(random_bytes(16));
        try {
            $steps['configured'] = config("filesystems.disks.{$disk}") !== null;
            $storage = Storage::disk($disk);
            $steps['write'] = $storage->put($key, $body) === true;
            $steps['exists'] = $steps['write'] && $storage->exists($key);
            $steps['read_hash'] = $steps['exists'] && hash_equals(hash('sha256', $body), hash('sha256', (string) $storage->get($key)));
        } catch (Throwable $e) {
            $error = class_basename($e);
        } finally {
            try {
                if (isset($storage)) {
                    $steps['delete'] = $storage->delete($key) === true || ! $steps['write'];
                    $steps['gone'] = ! $storage->exists($key);
                }
            } catch (Throwable $e) {
                $error ??= class_basename($e);
            }
        }
        $result = ['disk' => $disk, 'driver' => config("filesystems.disks.{$disk}.driver"), 'persistent' => SiteSettings::persistentDisk($disk),
            'passed' => ! in_array(false, $steps, true), 'steps' => $steps, 'error' => $error];
        $this->audit->record(AuditAction::SiteStorageChecked, 'site_settings', 'media', ['disk' => $disk, 'passed' => $result['passed'], 'error' => $error], $actor);

        return $result;
    }
}
