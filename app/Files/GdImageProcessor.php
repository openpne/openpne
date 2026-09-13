<?php

namespace App\Files;

use Intervention\Image\ImageManager;
use Throwable;

/**
 * The header is measured before anything is decoded: GD allocates width x height x 4 bytes per frame
 * outside PHP's memory_limit, and an out-of-memory kill cannot be caught (docs/internals/security.md,
 * "Decoding an upload").
 */
final class GdImageProcessor implements ImageProcessor
{
    /** Built with decodeAnimation off, so a decode allocates one frame whatever the source holds. */
    public function __construct(private readonly ImageManager $manager) {}

    public function preservesAnimation(): bool
    {
        return false;
    }

    public function process(string $bytes, string $mime, ImageSpec $spec): ProcessedImage
    {
        $this->preflight($bytes);

        try {
            $image = $this->manager->decode($bytes);
        } catch (Throwable $e) {
            throw new ImageProcessingException('The image could not be decoded: '.$e->getMessage(), 0, $e);
        }

        if ($image->isAnimated()) {
            $image->removeAnimation();
        }

        if ($spec->cover) {
            $image->cover((int) $spec->width, (int) $spec->height);
        } elseif (! $spec->isCanonical()) {
            $image->scaleDown((int) $spec->width, (int) $spec->height);
        }

        if ($spec->background !== null) {
            $image->fillTransparentAreas($spec->background);
        }

        try {
            $encoded = $image->encodeUsingFileExtension($spec->format, quality: (int) config('openpne.images.quality'));
        } catch (Throwable $e) {
            throw new ImageProcessingException('The image could not be encoded: '.$e->getMessage(), 0, $e);
        }

        return new ProcessedImage($encoded->toString(), $encoded->mediaType(), $image->width(), $image->height(), false);
    }

    private function preflight(string $bytes): void
    {
        $maxBytes = (int) config('openpne.images.max_source_kilobytes') * 1024;

        if ($maxBytes > 0 && strlen($bytes) > $maxBytes) {
            throw new ImageProcessingException(sprintf('The image is %d bytes, over the %d byte source limit.', strlen($bytes), $maxBytes));
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            throw new ImageProcessingException('The image header does not declare a size.');
        }

        $side = (int) config('openpne.images.max_upload_dimension');

        if ($info[0] > $side || $info[1] > $side) {
            throw new ImageProcessingException(sprintf('The image declares %dx%d, over the %d pixel limit.', $info[0], $info[1], $side));
        }
    }
}
