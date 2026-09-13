<?php

namespace App\Files;

use RuntimeException;

/** Deterministic for the bytes given: retrying with the same bytes fails the same way. */
class ImageProcessingException extends RuntimeException
{
    /** What every upload form shows when the picture is refused (why + what to do). */
    public static function userMessage(): string
    {
        return __('The image could not be processed safely. Re-saving it with an image editor may fix it.');
    }
}
