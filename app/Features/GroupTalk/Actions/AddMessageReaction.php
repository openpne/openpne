<?php

namespace App\Features\GroupTalk\Actions;

use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Exceptions\GroupTalkActionFailure;
use App\Features\GroupTalk\TalkReactionSurface;
use App\Features\Reactions\Actions\AddReaction;
use App\Features\Reactions\Exceptions\ReactionRefused;
use App\Models\GroupMessage;
use App\Models\Member;

class AddMessageReaction
{
    /**
     * Authorization is the caller's.
     *
     * @throws GroupTalkActionException
     */
    public function __invoke(Member $member, GroupMessage $message, string $emoji): void
    {
        try {
            app(AddReaction::class)($member, $message, $emoji, new TalkReactionSurface);
        } catch (ReactionRefused) {
            throw new GroupTalkActionException(GroupTalkActionFailure::UnknownMessage);
        }
    }
}
