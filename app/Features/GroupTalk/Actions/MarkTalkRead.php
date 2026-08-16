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
     * Record that the member has read as far as $messageId — or, with no id, as far as the
     * conversation goes.
     *
     * With an id the client names the last message it actually rendered, and the server resolves the
     * tuple from that row itself. Neither half is incidental. Taking the group's current newest
     * instead would mark read whatever arrived between the page loading and this call — messages
     * nobody has seen. Trusting a timestamp or a tuple from the client would let a bad one erase
     * future unread.
     *
     * Without an id the member is not reporting what they read but *declaring the backlog spent* —
     * the catch-up button on the absence digest. Only there does the group's own newest become the
     * right answer, and the server reads it here rather than accepting one the client fetched: a
     * client-fetched latest opens a window between the fetch and this write in which a message can
     * land, and it would be marked read having never been on anyone's screen. Read inside the same
     * operation, "latest" means latest at the moment the cursor moves, and anything after that
     * moment stays unread.
     *
     * The cursor lives on the membership row, so a reader without one — an Everyone group is
     * readable by any member — has nothing to advance and is refused rather than silently ignored.
     *
     * @throws GroupTalkActionException
     */
    public function __invoke(Member $member, Group $group, ?int $messageId): void
    {
        $groupId = (int) $group->getKey();
        $memberId = (int) $member->getKey();

        if (! TalkReadCursor::exists($groupId, $memberId)) {
            throw new GroupTalkActionException(GroupTalkActionFailure::NotMember);
        }

        if ($messageId === null) {
            // Forward only, like every other advance: a slow request that read an older newest —
            // the message it saw has since been deleted, or a concurrent one got there first —
            // cannot pull the cursor back over messages already marked read.
            $latest = TalkReadCursor::snapshot($groupId);
            TalkReadCursor::advance($groupId, $memberId, $latest['talk_read_at'], $latest['talk_read_message_id']);

            return;
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
