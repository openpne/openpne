<?php

namespace App\Files;

/** A refusal from the declared size or length alone, before anything is decoded. */
class ImageTooLargeException extends ImageProcessingException
{
    public static function userMessage(): string
    {
        return __('The image is too large. Make it smaller and send it again.');
    }
}
