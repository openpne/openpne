<?php

namespace App\Features\GroupTalk\Queries;

use App\Models\Group;
use App\Models\GroupMessage;

/**
 * The one most recent message in a group's talk — what the group page's talk card previews.
 *
 * Deliberately not GroupTalkMessages::latest(), which reads a whole page (PER_PAGE rows plus one) to
 * answer whether more history exists. A card that shows one line has no use for the other fifty, and
 * every group page would pay for them.
 *
 * Ordered by the same `(created_at, id)` tuple as everything else in talk: `id` alone is not
 * chronological on migrated rows, and `created_at` alone is not a total order at MySQL's
 * second-precise timestamps.
 */
class LatestGroupMessage
{
    public function __invoke(Group $group): ?GroupMessage
    {
        return GroupMessage::query()
            ->where('group_id', $group->getKey())
            ->with('author')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }
}
