<?php

namespace App\Features\GroupTalk\Exceptions;

enum GroupTalkActionFailure: string
{
    case CannotPost = 'cannot_post';
    case CannotDelete = 'cannot_delete';
    case NotMember = 'not_member';
    /** The named message is not a live row of this group — deleted, or another conversation's. */
    case UnknownMessage = 'unknown_message';
    /** Resolution dropped a mention the caller required it to keep; the write rolled back with it. */
    case MentionDropped = 'mention_dropped';
}
