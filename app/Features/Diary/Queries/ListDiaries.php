<?php

namespace App\Features\Diary\Queries;

use App\Features\Block\BlockLookup;
use App\Features\Diary\Visibility;
use App\Models\Diary;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListDiaries
{
    /** @return LengthAwarePaginator<int, Diary> */
    public function __invoke(Member $viewer, Member $owner, int $perPage = 20): LengthAwarePaginator
    {
        $query = $owner->diaries()->with('member');

        if (! $viewer->is($owner) && BlockLookup::ownerBlocksViewer($owner, $viewer)) {
            $query->whereRaw('1 = 0');
        } else {
            $clearance = Visibility::clearanceFor($viewer, $owner);
            $query->where('visibility', '<=', $clearance->value);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }
}
