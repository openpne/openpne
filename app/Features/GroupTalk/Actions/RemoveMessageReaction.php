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
     * Authorization is the caller's.
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
