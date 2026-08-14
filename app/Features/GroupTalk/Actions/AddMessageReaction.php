<?php

namespace App\Features\GroupTalk\Actions;

use App\Features\GroupTalk\TalkReactionVersion;
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
     * The insert decides, not a read-then-write: `insertOrIgnore` leans on the unique key, so a
     * double tap and a retried request settle at one row without a race to lose. Only a row that was
     * actually inserted moves the version — bumping on a no-op would wake every open tab in the
     * group for a message that reads exactly the same.
     *
     * `reactable_type` comes from the model's morph alias rather than a literal, so the value stored
     * follows the morphMap.
     */
    public function __invoke(Member $member, GroupMessage $message, string $emoji): void
    {
        DB::transaction(function () use ($member, $message, $emoji): void {
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
