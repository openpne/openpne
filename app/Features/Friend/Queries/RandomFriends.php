<?php

namespace App\Features\Friend\Queries;

use App\Models\Member;
use Illuminate\Support\Collection;

/** `inRandomOrder()` sorts one member's friend list, not the member table. */
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
