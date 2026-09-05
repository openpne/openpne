<?php

namespace App\Features\GroupTalk\Queries;

use App\Models\Group;
use App\Models\GroupMessage;

/**
 * Deliberately not `GroupTalkMessages::latest()`, which reads a whole page to answer whether more
 * history exists.
 */
class LatestGroupMessage
{
    public function __invoke(Group $group): ?GroupMessage
    {
        return GroupMessage::query()
            ->where('group_id', $group->getKey())
            ->with('author')
            ->withExists('images')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }
}
