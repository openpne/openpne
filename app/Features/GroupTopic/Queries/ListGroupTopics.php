<?php

namespace App\Features\GroupTopic\Queries;

use App\Models\Group;
use App\Models\GroupTopic;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * updated_at is the activity key — a new comment touches it — so a thread with fresh replies sorts
 * above an untouched one. id breaks ties for a stable order.
 */
class ListGroupTopics
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, GroupTopic> */
    public function __invoke(Group $group, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $group->topics()
            ->withCount('comments')
            ->with('member.avatar.file')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
