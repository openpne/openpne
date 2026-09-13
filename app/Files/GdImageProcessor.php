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
        ImageSourceLimit::preflight($bytes);

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
}
