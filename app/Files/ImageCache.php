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
     *
     * $maxBytes bounds the read of the stored bytes: a file that outgrows it is refused with
     * ImageBytesOverLimitException instead of being read, so a caller working to a budget never
     * holds more than it could answer with. Null reads whatever is stored. A cache hit is served
     * unbounded: its bytes were produced here, to a whitelisted size.
     */
    public function bytes(File $file, ImageTransform $transform, string $format, ?int $maxBytes = null): string
    {
        if ($transform->isRaw()) {
            return $this->original($file, $maxBytes);
        }

        $disk = $this->disk();
        $key = $transform->cacheKey($file->name, $format);

        if ($disk->exists($key)) {
            return (string) $disk->get($key);
        }

        $bytes = $this->generate($file, $transform, $format, $maxBytes);
        $disk->put($key, $bytes);

        return $bytes;
    }

    /** Remove every cached variant of $file (idempotent; a no-op when none exist). */
    public function purge(File $file): void
    {
        $this->disk()->deleteDirectory($file->name);
    }

    private function generate(File $file, ImageTransform $transform, string $format, ?int $maxBytes = null): string
    {
        // The budget bounds the source rather than the thumbnail made from it: a thumbnail is never
        // larger than its source, so a source over the budget cannot yield an answer that fits. It
        // keeps an oversized source out of the decoder as well as out of memory.
        $image = $this->decoder->decode($this->original($file, $maxBytes));

        if ($transform->square) {
            // Center-crop to fill the target box exactly, whatever its ratio.
            $image->cover($transform->width, $transform->height);
        } else {
            // Fit within the box, preserving aspect ratio and never upscaling.
            $image->scaleDown($transform->width, $transform->height);
        }

        return $image->encodeUsingFileExtension($format, quality: (int) config('openpne.images.quality'))->toString();
    }

    private function original(File $file, ?int $maxBytes = null): string
    {
        $stream = $this->storage->readStream($file);

        try {
            if ($maxBytes === null) {
                return (string) stream_get_contents($stream);
            }

            // One byte past the budget settles whether the file fits, and is the most this may pull
            // into memory: reading it whole and measuring afterwards is what the bound exists to
            // avoid. max() keeps a spent budget from reaching stream_get_contents as its read-it-all
            // sentinel.
            $bytes = (string) stream_get_contents($stream, max($maxBytes, 0) + 1);

            if (strlen($bytes) > $maxBytes) {
                throw new ImageBytesOverLimitException(
                    "The stored bytes of file [{$file->id}] outgrow the {$maxBytes} byte budget of this read.",
                );
            }

            return $bytes;
        } finally {
            fclose($stream);
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('openpne.images.cache_disk'));
    }
}
