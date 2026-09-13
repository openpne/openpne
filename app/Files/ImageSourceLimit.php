<?php

declare(strict_types=1);

namespace App\Files;

/**
 * What a processor will read and decode of a stored image, which an OpenPNE 3 row never had checked
 * at upload. Blank or non-positive follows the upload rules (the upload cap in bytes, the per-side
 * limit squared in pixels) so a stored upload never fails it; a set value is taken as given.
 */
final class ImageSourceLimit
{
    public const DEFAULT_KILOBYTES = 20480;

    public static function bytes(): int
    {
        $configured = (int) config('openpne.images.max_source_kilobytes');

        return ($configured > 0 ? $configured : max(self::DEFAULT_KILOBYTES, UploadLimit::kilobytes())) * 1024;
    }

    public static function pixels(): int
    {
        $configured = (int) config('openpne.images.max_source_pixels');
        $side = UploadLimit::dimension();

        return $configured > 0 ? $configured : $side * $side;
    }

    /**
     * Refuses from the header alone, before any processor allocates: bytes over bytes(), a declared
     * side over UploadLimit::dimension(), or more declared pixels than pixels().
     *
     * @throws ImageProcessingException
     */
    public static function preflight(string $bytes): void
    {
        $maxBytes = self::bytes();

        if (strlen($bytes) > $maxBytes) {
            throw new ImageProcessingException(sprintf('The image is %d bytes, over the %d byte source limit.', strlen($bytes), $maxBytes));
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            throw new ImageProcessingException('The image header does not declare a size.');
        }

        $side = UploadLimit::dimension();

        if ($info[0] > $side || $info[1] > $side) {
            throw new ImageProcessingException(sprintf('The image declares %dx%d, over the %d px side limit.', $info[0], $info[1], $side));
        }

        $pixels = self::pixels();

        if ($pixels < $info[0] * $info[1]) {
            throw new ImageProcessingException(sprintf('The image declares %dx%d, over the %d pixel limit.', $info[0], $info[1], $pixels));
        }
    }
}
