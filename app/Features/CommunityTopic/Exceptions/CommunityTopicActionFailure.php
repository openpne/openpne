<?php

namespace App\Features\CommunityTopic\Exceptions;

enum CommunityTopicActionFailure: string
{
    case CannotPost = 'cannot_post';
    case CannotEdit = 'cannot_edit';
    case CannotComment = 'cannot_comment';
    case CannotDeleteComment = 'cannot_delete_comment';
}
