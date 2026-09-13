<?php

namespace App\Files;

use RuntimeException;

/** Transient: the backend could not be reached or answered with an outage, not a verdict on the bytes. */
class ImageProcessorUnavailableException extends RuntimeException
{
    public static function userMessage(): string
    {
        return __('Image processing is temporarily unavailable. Please try again in a moment.');
    }
}
