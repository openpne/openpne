<?php

namespace App\Features\GroupTalk\Actions;

use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Exceptions\GroupTalkActionFailure;
use App\Features\GroupTalk\TalkReactionVersion;
use App\Features\GroupTalk\TalkWriteLock;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class RemoveMessageReaction
{
    /**
     * Take one of this member's reactions back, or do nothing if it is not there. Authorization is
     * the caller's, as it is for {@see AddMessageReaction}, and the message is re-read under the
     * same {@see TalkWriteLock} — a delete could not orphan anything, but taking the lock in one
     * order across both writes is what keeps them from deadlocking against a teardown.
     *
     * $emoji is deliberately unchecked against the vocabulary: a member must be able to undo a
     * reaction the site has since stopped offering, so what may be added and what may be removed are
     * different questions.
     *
     * Only an actual deletion moves the version, for the same reason the add does.
     *
     * @throws GroupTalkActionException
     */
    public function __invoke(Member $member, GroupMessage $message, string $emoji): void
    {
        DB::transaction(function () use ($member, $message, $emoji): void {
            if (! TalkWriteLock::hold($message)) {
                throw new GroupTalkActionException(GroupTalkActionFailure::UnknownMessage);
            }

            $deleted = $message->reactions()
                // The relation carries the chips' display order, which a DELETE has no use for.
                ->reorder()
                ->where('member_id', $member->getKey())
                ->where('emoji', $emoji)
                ->delete();

            if ($deleted > 0) {
                TalkReactionVersion::bump($message);
            }
        });
    }
}
