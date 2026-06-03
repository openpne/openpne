<?php

namespace App\Features\Diary\Queries;

use App\Features\Block\BlockLookup;
use App\Features\Diary\ArchivePeriod;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListDiaries
{
    /**
     * A member's diary archive under the viewer's clearance. With $period set, narrowed to a
     * calendar month/day — the OpenPNE 3 calendar archive, which is the same listMember view.
     *
     * @return LengthAwarePaginator<int, Diary>
     */
    public function __invoke(Member $viewer, Member $owner, ?ArchivePeriod $period = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = $owner->diaries()->with('member');

        if (! $viewer->is($owner) && BlockLookup::ownerBlocksViewer($owner, $viewer)) {
            $query->whereRaw('1 = 0');
        } else {
            $clearance = Visibility::clearanceFor($viewer, $owner);
            $query->where('visibility', '<=', $clearance->value);
        }

        if ($period !== null) {
            $query->where('created_at', '>=', $period->start)->where('created_at', '<', $period->end);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }
}
