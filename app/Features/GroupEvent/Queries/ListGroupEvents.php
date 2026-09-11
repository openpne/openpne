<?php

namespace App\Features\GroupEvent\Queries;

use App\Models\Group;
use App\Models\GroupEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Ordered by (bumped_at, id), not open_date (docs/internals/group-boards.md, "The board key is bumped_at"). */
class ListGroupEvents
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, GroupEvent> */
    public function __invoke(Group $group, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $group->events()
            ->withCount(['comments', 'participants'])
            ->with('member.avatar.file')
            ->orderByDesc('bumped_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
