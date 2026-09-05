<?php

namespace App\Features\GroupEvent\Queries;

use App\Models\Group;
use App\Models\GroupEvent;
use Illuminate\Support\Collection;

class RecentGroupEvents
{
    public const LIMIT = 5;

    /** @return Collection<int, GroupEvent> */
    public function __invoke(Group $group, int $limit = self::LIMIT): Collection
    {
        return $group->events()
            ->withCount(['comments', 'participants'])
            ->with('member.avatar.file')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
