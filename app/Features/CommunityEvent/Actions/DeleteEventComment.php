<?php

namespace App\Features\CommunityEvent\Actions;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionException;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionFailure;
use App\Models\CommunityEventComment;
use App\Models\Member;

class DeleteEventComment
{
    public function __invoke(Member $actor, CommunityEventComment $comment): void
    {
        if (! CommunityEventAccess::canDeleteComment($comment, $actor)) {
            throw new CommunityEventActionException(CommunityEventActionFailure::CannotDeleteComment);
        }

        // OpenPNE 3 leaves the remaining numbers and the event timestamps untouched on delete.
        $comment->delete();
    }
}
