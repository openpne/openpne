<?php

declare(strict_types=1);

namespace App\Files;

/**
 * The per-file cap on every image upload, browser and MCP alike; PHP's own ini limits are not folded
 * in, so a cap above `upload_max_filesize` refuses as a failed upload rather than as a size
 * (docs/internals/images.md, "Upload size"). A blank or non-positive setting is the shipped default,
 * not a cap of zero that would refuse every upload on the site.
 */
final class UploadLimit
{
    public const DEFAULT_KILOBYTES = 5120;

    public const DEFAULT_DIMENSION = 5000;

    /** Per-side pixel limit on an upload, and the side a stored image may declare; blank is the default. */
    public static function dimension(): int
    {
        $configured = (int) config('openpne.images.max_upload_dimension');

        return $configured > 0 ? $configured : self::DEFAULT_DIMENSION;
    }

    public static function kilobytes(): int
    {
        $configured = (int) config('openpne.images.max_upload_kilobytes');

        return $configured > 0 ? $configured : self::DEFAULT_KILOBYTES;
    }

    public static function bytes(): int
    {
        return self::kilobytes() * 1024;
    }
}
