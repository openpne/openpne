<?php

namespace App\Features\GroupTalk\Actions;

use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Exceptions\GroupTalkActionFailure;
use App\Features\GroupTalk\GroupTalkPermissions;
use App\Models\GroupMessage;
use App\Models\Member;

class DeleteGroupMessage
{
    /**
     * Retract a message. Physical, like every other deletion in the group boards — talk keeps no
     * tombstone, and the read cursor holds copied values rather than a reference, so a deleted
     * message leaves nothing dangling behind it.
     *
     * No image purge yet: nothing can attach one, so there are no bytes to reclaim. The image PR
     * extends this the way DeleteTopicComment already does.
     *
     * @throws GroupTalkActionException
     */
    public function __invoke(Member $actor, GroupMessage $message): void
    {
        if (! GroupTalkPermissions::for($message->group, $actor)->canDelete($message)) {
            throw new GroupTalkActionException(GroupTalkActionFailure::CannotDelete);
        }

        $message->delete();
    }
}
