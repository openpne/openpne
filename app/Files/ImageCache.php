<?php

namespace App\Files;

use App\Models\File;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Generates image thumbnails on demand and caches them on a filesystem disk, keyed by
 * the file's name token plus the transform. The original bytes are read through the
 * FileStorage seam, so the cache works the same on any storage backend.
 *
 * A thumbnail is always a still image, so an animated source is thumbnailed from its
 * first frame (see StillImageDecoder). The original size is served untouched, which is
 * where an uploaded animation still plays — as in OpenPNE 3.
 */
class ImageCache
{
    public function __construct(
        private readonly FileStorage $storage,
        private readonly StillImageDecoder $decoder,
    ) {}

    /**
     * The thumbnail bytes for $file under $transform, generating and caching on a miss.
     * The original size (`w_h`) returns the stored bytes unchanged — there is nothing
     * to transform or cache.
     */
    public function bytes(File $file, ImageTransform $transform, string $format): string
    {
        if ($transform->isRaw()) {
            return $this->original($file);
        }

        $disk = $this->disk();
        $key = $transform->cacheKey($file->name, $format);

        if ($disk->exists($key)) {
            return (string) $disk->get($key);
        }

        $bytes = $this->generate($file, $transform, $format);
        $disk->put($key, $bytes);

        return $bytes;
    }

    /** Remove every cached variant of $file (idempotent; a no-op when none exist). */
    public function purge(File $file): void
    {
        $this->disk()->deleteDirectory($file->name);
    }

    private function generate(File $file, ImageTransform $transform, string $format): string
    {
        $image = $this->decoder->decode($this->original($file));

        if ($transform->square) {
            // Center-crop to fill the target box exactly (OpenPNE 3 square behavior).
            $image->cover($transform->width, $transform->height);
        } else {
            // Fit within the box, preserving aspect ratio and never upscaling.
            $image->scaleDown($transform->width, $transform->height);
        }

        return $image->encodeUsingFileExtension($format, quality: (int) config('openpne.images.quality'))->toString();
    }

    private function original(File $file): string
    {
        $stream = $this->storage->readStream($file);
        $bytes = stream_get_contents($stream);
        fclose($stream);

        return (string) $bytes;
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('openpne.images.cache_disk'));
    }
}
