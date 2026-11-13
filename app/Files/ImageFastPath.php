<?php

namespace App\Files;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Publishes what bootstrap/image-fast-path.php needs to check a signed variant URL before the
 * framework boots, and removes it wherever the fast path cannot apply (docs/internals/images.md, "The fast path").
 */
final class ImageFastPath
{
    public const COOKIE = 'openpne-img';

    public const SECURE_COOKIE = '__Host-openpne-img';

    private const SCHEMA = 1;

    public static function path(): string
    {
        return storage_path('framework/image-fast-path.json');
    }

    /** Never the session's prefix rule: this cookie is host-only whatever `session.domain` says. */
    public static function cookieName(): string
    {
        return config('session.secure') ? self::SECURE_COOKIE : self::COOKIE;
    }

    /**
     * @return array{schema: int, key: string, host: string, root: string, encoder: string, version: string, cookie: string}|null
     *                                                                                                                            null where the fast path is switched off or cannot apply
     */
    public static function desired(): ?array
    {
        if (! config('openpne.images.fast_path')) {
            return null;
        }

        $root = self::localRoot((string) config('openpne.images.cache_disk'));
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        $appKey = (string) config('app.key');

        if ($root === null || ! is_string($host) || $host === '' || $appKey === '') {
            return null;
        }

        return [
            'schema' => self::SCHEMA,
            // Derived, so the file never holds the application key itself.
            'key' => hash_hmac('sha256', 'image-fast-path', $appKey),
            'host' => strtolower($host),
            'root' => $root,
            'encoder' => ImageTransform::encoder(),
            'version' => ImageTransform::version(),
            'cookie' => self::cookieName(),
        ];
    }

    /** Asked of the disk in use rather than its configuration, which a disk swapped at runtime no longer describes. */
    private static function localRoot(string $name): ?string
    {
        if (config("filesystems.disks.{$name}.driver") !== 'local') {
            return null;
        }

        $disk = Storage::disk($name);
        $root = $disk instanceof FilesystemAdapter && $disk->getAdapter() instanceof LocalFilesystemAdapter ? rtrim($disk->path(''), '/') : '';

        return str_starts_with($root, '/') ? $root : null;
    }

    /**
     * True only when the file on disk now says exactly what desired() does, which is the condition
     * for signing a URL; a failed write leaves whatever was there and answers false.
     */
    public static function sync(): bool
    {
        $desired = self::desired();
        $path = self::path();
        $current = is_file($path) ? @file_get_contents($path) : false;

        if ($desired === null) {
            if ($current !== false) {
                @unlink($path);
            }

            return false;
        }

        $json = json_encode($desired, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if ($current === $json) {
            return true;
        }

        // Mode first, contents second: the key signs any cached variant, so it is never readable by another user of the host.
        $temp = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $written = @touch($temp) && @chmod($temp, 0600) && @file_put_contents($temp, $json) === strlen($json) && @rename($temp, $path);

        if (! $written) {
            @unlink($temp);
        }

        return $written;
    }
}
