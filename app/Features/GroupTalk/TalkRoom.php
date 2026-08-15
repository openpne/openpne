<?php

namespace App\Features\GroupTalk;

use App\Models\Group;
use App\Models\GroupMessage;

/**
 * One row of the room list: a group the viewer belongs to, their own state in it, and the message
 * it leads with — null while nothing has been said there.
 */
final class TalkRoom
{
    public function __construct(
        public readonly Group $group,
        public readonly int $unread,
        public readonly bool $muted,
        public readonly ?GroupMessage $latest,
        /** How many of the unread name the viewer, or null when the read did not ask (JoinedTalkRooms). */
        public readonly ?int $unreadMentions = null,
    ) {}
}
