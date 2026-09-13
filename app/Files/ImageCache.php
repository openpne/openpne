<?php

namespace App\Files;

use App\Models\File;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * A thumbnail is always a still image; the original size is served untouched, so that is where an
 * uploaded animation still plays (docs/internals/security.md, "Decoding an upload").
 */
class ImageCache
{
    public function __construct(
        private readonly FileStorage $storage,
        private readonly ImageProcessor $processor,
    ) {}

    /**
     * $maxBytes bounds the read of the stored bytes: a file that outgrows it is refused with
     * ImageBytesOverLimitException rather than read. A cache hit is served unbounded, its bytes
     * having been produced here to a whitelisted size.
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
        // The budget bounds the source, not the thumbnail: a thumbnail is never larger than its
        // source, so an over-budget source cannot yield an answer that fits.
        $bytes = $this->original($file, $maxBytes);

        $spec = $transform->square
            ? ImageSpec::cover((int) $transform->width, (int) $transform->height, $format)
            : ImageSpec::fit((int) $transform->width, (int) $transform->height, $format);

        return $this->processor->process($bytes, $file->type, $spec)->bytes;
    }

    private function original(File $file, ?int $maxBytes = null): string
    {
        $stream = $this->storage->readStream($file);

        try {
            if ($maxBytes === null) {
                return (string) stream_get_contents($stream);
            }

            // One byte past the budget settles whether the file fits, and max() keeps a spent
            // budget from reaching stream_get_contents as its read-it-all sentinel.
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
