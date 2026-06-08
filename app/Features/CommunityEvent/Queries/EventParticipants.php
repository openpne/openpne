<?php

namespace App\Features\CommunityEvent\Queries;

use App\Models\CommunityEvent;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * An event's participant roster (OpenPNE 3 communityEvent/memberList), paged. Ordered by join time
 * so the roster reads in the order people signed up.
 */
class EventParticipants
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, Member> */
    public function __invoke(CommunityEvent $event, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $event->participants()
            ->orderBy('community_event_members.id')
            ->paginate($perPage);
    }
}
