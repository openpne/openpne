<?php

namespace App\Features\Diary\Exceptions;

enum DiaryActionFailure
{
    case NotAuthor;
    // Concurrent-edit race backstop: the image cap is the request's primary gate, so this only
    // fires when a parallel edit freed fewer slots than the validated payload assumed.
    case TooManyImages;
}
