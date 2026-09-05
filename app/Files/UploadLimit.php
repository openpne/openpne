<?php

declare(strict_types=1);

namespace App\Files;

/**
 * The per-file cap on every image upload, browser and MCP alike; PHP's own ini limits are not folded
 * in, so a cap above `upload_max_filesize` refuses as a failed upload rather than as a size
 * (docs/internals/images.md, "Upload size").
 */
final class UploadLimit
{
    public static function kilobytes(): int
    {
        return max(0, (int) config('openpne.images.max_upload_kilobytes'));
    }

    public static function bytes(): int
    {
        return self::kilobytes() * 1024;
    }
}
