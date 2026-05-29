<?php

namespace App\Features\Block\Queries;

use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListBlocks
{
    /** @return LengthAwarePaginator<int, Member> */
    public function __invoke(Member $blocker, int $perPage = 20): LengthAwarePaginator
    {
        return $blocker->blocksMade()->paginate($perPage);
    }
}
