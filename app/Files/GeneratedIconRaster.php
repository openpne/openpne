<?php

declare(strict_types=1);

namespace App\Files;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * The generated icon's raster forms, drawn by an administrator's browser and kept per version on
 * the image cache disk (docs/internals/site-icon.md, "The generated icon").
 */
class GeneratedIconRaster
{
    public const SIZES = [32, 180, 192, 512];

    public const MAX_BYTES = 262144;

    /** Leading underscore: FileUploader's tokens are alphanumeric, so no file's cache directory is this one. */
    private const PREFIX = '_site-icon';

    /** @var array<string, bool> per-request memo (the class is scoped), since every page's head asks */
    private array $known = [];

    public function __construct(private readonly ImageCache $cache) {}

    public function has(string $version): bool
    {
        return $this->known[$version] ??= $this->disk()->exists(self::PREFIX."/{$version}/complete");
    }

    public function bytes(string $version, int $size): ?string
    {
        if (! $this->has($version)) {
            return null;
        }

        $bytes = $this->disk()->get(self::PREFIX."/{$version}/{$size}.png");

        return $bytes === null || $bytes === '' ? null : $bytes;
    }

    /**
     * Validated in full before anything is written, and nothing else is deleted here: prune() is a
     * separate call, so the caller decides which version survives.
     *
     * @param  array<int, string>  $pngs  keyed by size, one per SIZES entry
     *
     * @throws InvalidArgumentException
     * @throws ImageCachePublishException
     */
    public function store(string $version, array $pngs): void
    {
        foreach (self::SIZES as $size) {
            $png = $pngs[$size] ?? null;

            if (! is_string($png) || strlen($png) > self::MAX_BYTES) {
                throw new InvalidArgumentException("The {$size}px icon is missing or too large.");
            }

            $info = @getimagesizefromstring($png);

            if ($info === false || $info[2] !== IMAGETYPE_PNG || $info[0] !== $size || $info[1] !== $size) {
                throw new InvalidArgumentException("The {$size}px icon is not a {$size}x{$size} PNG.");
            }
        }

        foreach (self::SIZES as $size) {
            $this->cache->publish(self::PREFIX."/{$version}/{$size}.png", $pngs[$size]);
        }

        $this->cache->publish(self::PREFIX."/{$version}/complete", '');
        $this->known[$version] = true;
    }

    /** Deletes every version but $keep ('' for all), read by the caller after its own write so a late write for an old version never removes a newer one. */
    public function prune(string $keep): void
    {
        foreach ($this->disk()->directories(self::PREFIX) as $directory) {
            if (basename($directory) !== $keep) {
                $this->disk()->deleteDirectory($directory);
                unset($this->known[basename($directory)]);
            }
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('openpne.images.cache_disk'));
    }
}
