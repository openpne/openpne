<?php

namespace App\Features\DirectMessage\Exceptions;

enum DirectMessageActionFailure: string
{
    case CannotSend = 'cannot_send';
    case TooManyImages = 'too_many_images';
    /** The named message is not one this conversation can see — another's, a draft, or trashed. */
    case UnknownMessage = 'unknown_message';
}
