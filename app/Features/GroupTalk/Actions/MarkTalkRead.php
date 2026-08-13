<?php

namespace App\Features\GroupTalk\Actions;

use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Exceptions\GroupTalkActionFailure;
use App\Features\GroupTalk\TalkReadCursor;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use Carbon\CarbonImmutable;

class MarkTalkRead
{
    /**
     * Record that the member has read as far as $messageId.
     *
     * The client names the last message it actually rendered, and the server resolves the tuple from
     * that row itself. Neither half is incidental. Taking the group's current newest instead would
     * mark read whatever arrived between the page loading and this call — messages nobody has seen.
     * Trusting a timestamp or a tuple from the client would let a bad one erase future unread.
     *
     * The cursor lives on the membership row, so a reader without one — an Everyone group is
     * readable by any member — has nothing to advance and is refused rather than silently ignored.
     *
     * @throws GroupTalkActionException
     */
    public function __invoke(Member $member, Group $group, int $messageId): void
    {
        $groupId = (int) $group->getKey();
        $memberId = (int) $member->getKey();

        if (! TalkReadCursor::exists($groupId, $memberId)) {
            throw new GroupTalkActionException(GroupTalkActionFailure::NotMember);
        }

        // Live row of THIS group: a deleted message, or one belonging to another conversation,
        // resolves to no tuple at all rather than to someone else's clock.
        $message = GroupMessage::query()
            ->where('group_id', $groupId)
            ->whereKey($messageId)
            ->first(['id', 'created_at']);

        if ($message === null) {
            throw new GroupTalkActionException(GroupTalkActionFailure::UnknownMessage);
        }

        // Forward only, so replaying an older id — a retry, a second tab a page behind — is a no-op.
        TalkReadCursor::advance($groupId, $memberId, CarbonImmutable::instance($message->created_at), (int) $message->getKey());
    }
}
