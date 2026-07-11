<?php

namespace App\Files;

use RuntimeException;

/**
 * A structurally unparseable image reached the metadata stripper. Upstream validation already
 * cleared its magic bytes and dimensions, so a parse failure here means the container is corrupt
 * or adversarial — a privacy control fails closed rather than passing the original bytes through.
 */
class ImageMetadataStripException extends RuntimeException
{
    /** The user-facing message every upload path shows when a strip fails closed (why + what to do). */
    public static function userMessage(): string
    {
        return __('The image could not be processed safely. Re-saving it with an image editor may fix it.');
    }
}
