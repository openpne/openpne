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
