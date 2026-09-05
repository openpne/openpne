<?php

namespace App\Features\GroupTopic\Queries;

use App\Models\Group;
use App\Models\GroupTopic;
use Illuminate\Support\Collection;

class RecentGroupTopics
{
    public const LIMIT = 5;

    /** @return Collection<int, GroupTopic> */
    public function __invoke(Group $group, int $limit = self::LIMIT): Collection
    {
        return $group->topics()
            ->withCount('comments')
            ->with('member.avatar.file')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
