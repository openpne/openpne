<?php

namespace App\Files;

use App\Models\File;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Every variant is drawn from the canonical, the full-size re-encode kept at the `w_h` key, so the
 * stored bytes are decoded once per file and a variant never reads them (docs/internals/images.md,
 * "files.width / files.height"). A refused canonical leaves a marker beside its key, and a miss on a
 * marked file is answered from the marker without decoding again.
 */
class ImageCache
{
    private const MARKER = 'w_h.failed';

    public function __construct(
        private readonly FileStorage $storage,
        private readonly ImageProcessor $processor,
    ) {}

    /**
     * $maxBytes bounds the read of the stored bytes and of a canonical hit: a file that outgrows it is
     * refused with ImageBytesOverLimitException rather than read. A variant hit is served unbounded,
     * its bytes having been produced here to a whitelisted size.
     *
     * @throws CanonicalUnavailableException
     * @throws ImageProcessorUnavailableException
     */
    public function bytes(File $file, ImageTransform $transform, string $format, ?int $maxBytes = null): string
    {
        if ($transform->isRaw()) {
            return $this->canonical($file, $maxBytes);
        }

        $disk = $this->disk();
        $key = $transform->cacheKey($file->name, $format);

        if ($disk->exists($key)) {
            return (string) $disk->get($key);
        }

        $spec = $transform->square
            ? ImageSpec::cover((int) $transform->width, (int) $transform->height, $format)
            : ImageSpec::fit((int) $transform->width, (int) $transform->height, $format);

        $bytes = $this->processor->process($this->canonical($file, $maxBytes), $file->type, $spec)->bytes;
        $this->publishOrReport($key, $bytes);

        return $bytes;
    }

    /**
     * The canonical bytes of $file, generated from the stored bytes on a miss. $maxBytes is the
     * caller's own budget for that read and never widens ImageSourceLimit; a source over the caller's
     * budget but within the limit is refused for this call only, without a marker.
     *
     * @throws CanonicalUnavailableException
     * @throws ImageBytesOverLimitException
     * @throws ImageProcessorUnavailableException
     */
    public function canonical(File $file, ?int $maxBytes = null): string
    {
        $disk = $this->disk();
        $key = $this->canonicalKey($file);

        if ($disk->exists($key) && ($cached = $this->cached($file, $key, $maxBytes)) !== null) {
            return $cached;
        }

        $marker = $this->markerKey($file);

        if ($disk->exists($marker)) {
            throw new CanonicalUnavailableException("File [{$file->id}] was refused: ".(string) $disk->get($marker));
        }

        $limit = ImageSourceLimit::bytes();
        $budget = $maxBytes === null ? $limit : min($maxBytes, $limit);

        try {
            $bytes = $this->original($file, $budget);
        } catch (ImageBytesOverLimitException $e) {
            if ($budget < $limit) {
                throw $e;
            }

            return $this->refuse($file, $marker, "over the {$limit} byte source limit");
        }

        try {
            $processed = $this->processor->process($bytes, $file->type, ImageSpec::canonical($this->format($file)));
        } catch (ImageProcessingException $e) {
            return $this->refuse($file, $marker, $e->getMessage());
        }

        // A canonical is itself the input of every variant, so it is held to the same source limit.
        if (strlen($processed->bytes) > $limit) {
            return $this->refuse($file, $marker, 'canonical of '.strlen($processed->bytes)." bytes, over the {$limit} byte source limit");
        }

        $this->publishOrReport($key, $processed->bytes);

        return $processed->bytes;
    }

    /**
     * A cache hit read to the caller's budget, so an understated row cannot put an unbounded object in
     * memory; null when the key vanished between exists() and the read, which the caller treats as a miss.
     */
    private function cached(File $file, string $key, ?int $maxBytes): ?string
    {
        $disk = $this->disk();

        if ($maxBytes === null) {
            return $disk->get($key);
        }

        $stream = $disk->readStream($key);

        if ($stream === null) {
            return null;
        }

        try {
            $bytes = (string) stream_get_contents($stream, max($maxBytes, 0) + 1);
        } finally {
            fclose($stream);
        }

        if (strlen($bytes) > $maxBytes) {
            throw new ImageBytesOverLimitException(
                "The canonical of file [{$file->id}] outgrows the {$maxBytes} byte budget of this read.",
            );
        }

        return $bytes;
    }

    /**
     * Write-through from an upload: the canonical FileUploader already produced. A cache disk that
     * refuses it is reported, not a reason to refuse the upload; the first view regenerates it.
     */
    public function putCanonical(File $file, ProcessedImage $processed): void
    {
        $this->publishOrReport($this->canonicalKey($file), $processed->bytes);
    }

    /** Remove every cached variant, canonical and marker of $file (idempotent; a no-op when none exist). */
    public function purge(File $file): void
    {
        $this->disk()->deleteDirectory($file->name);
    }

    /** Remove what the current encoder produced for $file, so the next read makes it again. */
    public function purgeGeneration(File $file): void
    {
        $this->disk()->deleteDirectory(ImageTransform::encoderPrefix($file->name));
    }

    public function hasCanonical(File $file): bool
    {
        return $this->disk()->exists($this->canonicalKey($file));
    }

    /** The reason the processor refused $file, or null when it has not. */
    public function refusal(File $file): ?string
    {
        $marker = $this->markerKey($file);

        return $this->disk()->exists($marker) ? (string) $this->disk()->get($marker) : null;
    }

    public function forgetRefusal(File $file): void
    {
        $this->disk()->delete($this->markerKey($file));
    }

    /**
     * Written to a sibling temp key and moved into place: the local adapter writes the final path in
     * place and readers take no lock, so a plain put can be read half-written and cached as a hit. Every
     * derived file on this disk that a browser may receive goes through here.
     *
     * @throws ImageCachePublishException
     */
    private function publish(string $key, string $bytes): void
    {
        $disk = $this->disk();
        $temp = dirname($key).'/.tmp-'.Str::random(16);

        try {
            if (! $disk->put($temp, $bytes)) {
                throw new ImageCachePublishException("The image cache refused to write [{$temp}].");
            }

            if (! $disk->move($temp, $key)) {
                $disk->delete($temp);

                throw new ImageCachePublishException("The image cache refused to move [{$temp}] into place.");
            }
        } catch (ImageCachePublishException $e) {
            throw $e;
        } catch (Throwable $e) {
            // The adapter throws for a missing directory it cannot create, and returns false for the rest.
            throw new ImageCachePublishException("The image cache refused [{$key}]: ".$e->getMessage(), 0, $e);
        }
    }

    /** On a read path the bytes in hand are still the answer; a cache disk failure is reported, not served as an error. */
    public function publishOrReport(string $key, string $bytes): void
    {
        try {
            $this->publish($key, $bytes);
        } catch (ImageCachePublishException $e) {
            report($e);
        }
    }

    private function refuse(File $file, string $marker, string $reason): never
    {
        $this->publishOrReport($marker, $reason);

        throw new CanonicalUnavailableException("File [{$file->id}] was refused: {$reason}");
    }

    private function canonicalKey(File $file): string
    {
        return ImageTransform::raw()->cacheKey($file->name, $this->format($file));
    }

    private function markerKey(File $file): string
    {
        return ImageTransform::encoderPrefix($file->name).'/'.self::MARKER;
    }

    private function format(File $file): string
    {
        return $file->imageFormat() ?? throw new ImageProcessingException("File [{$file->id}] of type [{$file->type}] is not a raster image.");
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
