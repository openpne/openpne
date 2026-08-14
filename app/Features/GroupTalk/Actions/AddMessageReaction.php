<?php

namespace App\Features\GroupTalk\Actions;

use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Exceptions\GroupTalkActionFailure;
use App\Features\GroupTalk\TalkReactionVersion;
use App\Features\GroupTalk\TalkWriteLock;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Models\Reaction;
use Illuminate\Support\Facades\DB;

class AddMessageReaction
{
    /**
     * React to a message, or do nothing if this member already has that emoji on it. Authorization
     * is the caller's (the controller answers a refusal with the same 404 every talk route does).
     *
     * The message is re-read under {@see TalkWriteLock} before anything is written: the gate is a
     * moment old by now, and a reaction on a deleted row is an orphan nothing would collect.
     *
     * The insert then decides, not a read-then-write: `insertOrIgnore` leans on the unique key, so a
     * double tap and a retried request settle at one row without a race to lose. Only a row that was
     * actually inserted moves the version — bumping on a no-op would wake every open tab in the
     * group for a message that reads exactly the same.
     *
     * `reactable_type` comes from the model's morph alias rather than a literal, so the value stored
     * follows the morphMap.
     *
     * @throws GroupTalkActionException
     */
    public function __invoke(Member $member, GroupMessage $message, string $emoji): void
    {
        DB::transaction(function () use ($member, $message, $emoji): void {
            if (! TalkWriteLock::hold($message)) {
                throw new GroupTalkActionException(GroupTalkActionFailure::UnknownMessage);
            }

            $now = now();

            $inserted = Reaction::query()->insertOrIgnore([
                'reactable_type' => $message->getMorphClass(),
                'reactable_id' => $message->getKey(),
                'member_id' => $member->getKey(),
                'emoji' => $emoji,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted > 0) {
                TalkReactionVersion::bump($message);
            }
        });
    }
}
