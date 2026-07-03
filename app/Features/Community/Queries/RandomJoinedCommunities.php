<?php

namespace App\Features\Community\Queries;

use App\Models\Community;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * A random handful of communities the viewer has confirmed-joined, for the right rail's communities
 * grid (OpenPNE 3's nineTable community gadget). The friend-grid counterpart: random, capped at nine.
 */
class RandomJoinedCommunities
{
    public const LIMIT = 9;

    /** @return Collection<int, Community> */
    public function __invoke(Member $viewer, int $limit = self::LIMIT): Collection
    {
        return Community::query()
            ->whereHas('members', fn ($q) => $q->where('member_id', $viewer->getKey()))
            ->with('image')
            ->inRandomOrder()
            ->limit($limit)
            ->get();
    }
}
