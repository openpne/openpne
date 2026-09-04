<?php

namespace App\Files;

/**
 * Delivery decodes through intervention/image, which auto-orients before it scales, so a size is
 * only useful to a caller if EXIF Orientation is applied to it first
 * ([images.md](../../docs/internals/images.md) § files.width / files.height).
 */
final class ImageDimensions
{
    /** Orientation values that rotate a quarter turn, so width and height trade places. */
    private const TRANSPOSING = [5, 6, 7, 8];

    /** Containers PHP's exif reads, and the only ones intervention/image auto-orients. */
    private const ORIENTABLE = ['image/jpeg', 'image/tiff'];

    /**
     * The rendered pixel size of $bytes, or null when they do not decode as an image. A zero side
     * counts as no size: a header-only decode reports one, and consumers divide by it.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function fromBytes(string $bytes): ?array
    {
        $size = @getimagesizefromstring($bytes);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        return self::isTransposed($bytes, (string) ($size['mime'] ?? ''))
            ? [$size[1], $size[0]]
            : [$size[0], $size[1]];
    }

    /**
     * Fail-open: without ext-exif, or with EXIF that will not parse, the declared size stands —
     * the same reading intervention/image makes when it cannot auto-orient either.
     */
    private static function isTransposed(string $bytes, string $mime): bool
    {
        if (! in_array($mime, self::ORIENTABLE, true) || ! function_exists('exif_read_data')) {
            return false;
        }

        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            return false;
        }

        fwrite($stream, $bytes);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);

        return is_array($exif) && in_array((int) ($exif['Orientation'] ?? 0), self::TRANSPOSING, true);
    }
}
