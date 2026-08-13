<?php

namespace App\Features\GroupTalk\Actions;

use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Exceptions\GroupTalkActionFailure;
use App\Features\GroupTalk\GroupTalkAccess;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;

class CreateGroupMessage
{
    /**
     * Say something in a group's talk. Membership is checked here rather than in the controller —
     * the write is the one place it cannot be routed around.
     *
     * $body arrives normalized and bounded by the form request (LF newlines, at most 5,000 code
     * points). in_reply_to_id is never written: it exists to receive what migrated content pointed
     * at, and talk has no reply UI to produce one.
     *
     * @throws GroupTalkActionException
     */
    public function __invoke(Member $author, Group $group, string $body): GroupMessage
    {
        if (! GroupTalkAccess::canPost($group, $author)) {
            throw new GroupTalkActionException(GroupTalkActionFailure::CannotPost);
        }

        return GroupMessage::create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'body' => $body,
        ]);
    }
}
