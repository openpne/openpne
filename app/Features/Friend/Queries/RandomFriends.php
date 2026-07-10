<?php

namespace App\Features\Friend\Queries;

use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * A random handful of the viewer's friends, for the right rail's friends grid. Random so the
 * grid varies between visits rather than always showing the
 * same nine; the small-SNS scale makes ORDER BY RANDOM() cheap.
 */
class RandomFriends
{
    public const LIMIT = 9;

    /** @return Collection<int, Member> */
    public function __invoke(Member $viewer, int $limit = self::LIMIT): Collection
    {
        return $viewer->friendships()
            ->with('avatar.file')
            ->inRandomOrder()
            ->limit($limit)
            ->get();
    }
}
