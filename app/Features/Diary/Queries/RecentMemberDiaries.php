<?php

namespace App\Features\Diary\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Support\Collection;

/**
 * The diary sidemenu's "Recently Posted Diaries" box (OpenPNE 3 Diary::getMemberDiaryList):
 * an author's own newest entries the viewer may see, with their comment count. A guest (no
 * Member, reached on a web-public profile) sees only Open entries.
 */
class RecentMemberDiaries
{
    /** @return Collection<int, Diary> */
    public function __invoke(?Member $viewer, Member $owner, int $limit = 5): Collection
    {
        $query = $owner->diaries()->withCount('comments');

        if ($viewer === null) {
            $query->where('visibility', '<=', Visibility::Open->value);
        } elseif (! $viewer->is($owner) && BlockLookup::ownerBlocksViewer($owner, $viewer)) {
            return collect();
        } else {
            $query->where('visibility', '<=', Visibility::clearanceFor($viewer, $owner)->value);
        }

        return $query->orderByDesc('created_at')->limit($limit)->get();
    }
}
