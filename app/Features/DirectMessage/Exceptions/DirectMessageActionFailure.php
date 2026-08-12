<?php

namespace App\Features\DirectMessage\Exceptions;

enum DirectMessageActionFailure: string
{
    case CannotSend = 'cannot_send';
    case TooManyImages = 'too_many_images';
}
