<?php

namespace App\Features\CommunityEvent\Queries;

use App\Models\Community;
use App\Models\CommunityEvent;
use Illuminate\Support\Collection;

/**
 * The most recently active events of a community, for the "recent events" box on the community home
 * (OpenPNE 3 community/home). Same ordering as the list, capped at a few rows.
 */
class RecentCommunityEvents
{
    public const LIMIT = 5;

    /** @return Collection<int, CommunityEvent> */
    public function __invoke(Community $community, int $limit = self::LIMIT): Collection
    {
        return $community->events()
            ->withCount('comments')
            ->with('member.avatar.file')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
