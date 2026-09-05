<?php

namespace App\Features\GroupTalk;

use App\Models\Group;
use App\Models\GroupMessage;

final class TalkRoom
{
    public function __construct(
        public readonly Group $group,
        public readonly int $unread,
        public readonly bool $muted,
        public readonly ?GroupMessage $latest,
        /** How many of the unread name the viewer, or null when the read did not ask. */
        public readonly ?int $unreadMentions = null,
    ) {}
}
