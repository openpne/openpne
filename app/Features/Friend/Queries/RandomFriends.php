<?php

namespace App\Features\Friend\Queries;

use App\Models\Member;
use Illuminate\Support\Collection;

/** `inRandomOrder()` runs over one member's friend rows only, so its cost is the friend count, not the member table. */
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
