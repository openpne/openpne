<?php

namespace App\Files;

use RuntimeException;

/**
 * Fails closed: the original bytes are never passed through
 * ([security.md](../../docs/internals/security.md) § Uploaded image metadata).
 */
class ImageMetadataStripException extends RuntimeException
{
    /** The user-facing message every upload path shows when a strip fails closed (why + what to do). */
    public static function userMessage(): string
    {
        return __('The image could not be processed safely. Re-saving it with an image editor may fix it.');
    }
}
