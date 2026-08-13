<?php

namespace App\Features\GroupTalk\Exceptions;

enum GroupTalkActionFailure: string
{
    case CannotPost = 'cannot_post';
    case CannotDelete = 'cannot_delete';
}
