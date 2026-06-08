<?php

namespace App\Features\CommunityEvent\Exceptions;

enum CommunityEventActionFailure: string
{
    case CannotPost = 'cannot_post';
    case CannotEdit = 'cannot_edit';
    case CannotComment = 'cannot_comment';
    case CannotDeleteComment = 'cannot_delete_comment';
    case NotMember = 'not_member';
    case EventClosed = 'event_closed';
    case EventExpired = 'event_expired';
    case EventAtCapacity = 'event_at_capacity';
    case TooManyImages = 'too_many_images';
}
