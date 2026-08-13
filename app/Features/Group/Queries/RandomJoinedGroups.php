<?php

namespace App\Features\Group\Queries;

use App\Models\Group;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * A random handful of groups the viewer has confirmed-joined, for the right rail's groups
 * grid (OpenPNE 3's nineTable community gadget). The friend-grid counterpart: random, capped at nine.
 */
class RandomJoinedGroups
{
    public const LIMIT = 9;

    /** @return Collection<int, Group> */
    public function __invoke(Member $viewer, int $limit = self::LIMIT): Collection
    {
        return Group::query()
            ->whereHas('members', fn ($q) => $q->where('member_id', $viewer->getKey()))
            ->with('image')
            ->inRandomOrder()
            ->limit($limit)
            ->get();
    }
}
