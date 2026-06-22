<?php

namespace App\Features\Message\Exceptions;

enum MessageActionFailure: string
{
    case CannotSend = 'cannot_send';
    case TooManyImages = 'too_many_images';
}
