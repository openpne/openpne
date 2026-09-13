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

    /** The shipped per-side upload limit, standing in when that setting is itself blank. */
    private const DEFAULT_SIDE = 5000;

    public static function bytes(): int
    {
        $configured = (int) config('openpne.images.max_source_kilobytes');

        return ($configured > 0 ? $configured : max(self::DEFAULT_KILOBYTES, UploadLimit::kilobytes())) * 1024;
    }

    public static function pixels(): int
    {
        $configured = (int) config('openpne.images.max_source_pixels');
        $side = (int) config('openpne.images.max_upload_dimension');
        $side = $side > 0 ? $side : self::DEFAULT_SIDE;

        return $configured > 0 ? $configured : $side * $side;
    }
}
