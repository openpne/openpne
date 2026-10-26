<?php

declare(strict_types=1);

namespace App\Files;

/**
 * The per-file cap on a non-picture attachment; PHP's own ini limits are not folded in, as for
 * UploadLimit (docs/internals/images.md, "Upload size"). A blank or non-positive setting is the
 * shipped default, not a cap of zero.
 */
final class AttachmentLimit
{
    public const DEFAULT_KILOBYTES = 10240;

    public static function kilobytes(): int
    {
        $configured = (int) config('openpne.files.max_upload_kilobytes');

        return $configured > 0 ? $configured : self::DEFAULT_KILOBYTES;
    }

    public static function bytes(): int
    {
        return self::kilobytes() * 1024;
    }
}
