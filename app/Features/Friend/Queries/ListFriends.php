<?php

namespace App\Features\Friend\Queries;

use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListFriends
{
    /** @return LengthAwarePaginator<int, Member> */
    public function __invoke(Member $viewer, Member $owner, int $perPage = 20): LengthAwarePaginator
    {
        return $owner->friendships()->paginate($perPage);
    }
}
