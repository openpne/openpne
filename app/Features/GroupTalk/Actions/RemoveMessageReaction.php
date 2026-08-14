<?php

namespace App\Features\GroupTalk\Actions;

use App\Features\GroupTalk\TalkReactionVersion;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class RemoveMessageReaction
{
    /**
     * Take one of this member's reactions back, or do nothing if it is not there. Authorization is
     * the caller's, as it is for {@see AddMessageReaction}.
     *
     * $emoji is deliberately unchecked against the vocabulary: a member must be able to undo a
     * reaction the site has since stopped offering, so what may be added and what may be removed are
     * different questions.
     *
     * Only an actual deletion moves the version, for the same reason the add does.
     */
    public function __invoke(Member $member, GroupMessage $message, string $emoji): void
    {
        DB::transaction(function () use ($member, $message, $emoji): void {
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
