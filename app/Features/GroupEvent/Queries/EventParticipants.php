<?php

namespace App\Features\GroupEvent\Queries;

use App\Models\GroupEvent;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EventParticipants
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, Member> */
    public function __invoke(GroupEvent $event, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $event->participants()
            ->with('avatar.file')
            // "name (friend count)" per row, as one subquery for the page.
            ->withCount('friendships')
            ->orderBy('group_event_members.id')
            ->paginate($perPage);
    }
}
