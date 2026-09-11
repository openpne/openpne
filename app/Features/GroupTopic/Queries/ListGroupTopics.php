<?php

namespace App\Features\GroupTopic\Queries;

use App\Models\Group;
use App\Models\GroupTopic;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Ordered by (bumped_at, id): the last comment lifts a thread (docs/internals/group-boards.md, "The board key is bumped_at"). */
class ListGroupTopics
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, GroupTopic> */
    public function __invoke(Group $group, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $group->topics()
            ->withCount('comments')
            ->with('member.avatar.file')
            ->orderByDesc('bumped_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
