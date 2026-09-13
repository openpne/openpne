<?php

declare(strict_types=1);

namespace App\Files;

/**
 * What a processor will read and decode of a stored image, which an OpenPNE 3 row never had checked
 * at upload; a blank or non-positive setting is the shipped default, as for UploadLimit, never no cap.
 */
final class ImageSourceLimit
{
    public const DEFAULT_KILOBYTES = 20480;

    /** 5000 x 5000: the upload rules' per-side default squared, so a stored upload never fails it. */
    public const DEFAULT_PIXELS = 25_000_000;

    public static function bytes(): int
    {
        $configured = (int) config('openpne.images.max_source_kilobytes');

        return ($configured > 0 ? $configured : self::DEFAULT_KILOBYTES) * 1024;
    }

    public static function pixels(): int
    {
        $configured = (int) config('openpne.images.max_source_pixels');

        return $configured > 0 ? $configured : self::DEFAULT_PIXELS;
    }
}
