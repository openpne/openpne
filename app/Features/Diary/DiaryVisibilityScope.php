<?php

namespace App\Features\Diary;

use App\Features\Block\BlockLookup;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * Constrains a query over one author's diaries to what a viewer may see, matching OpenPNE 3
 * getPublicFlagByMemberId + addPublicFlagQuery: a guest (no Member) sees only web-public, a
 * blocked viewer sees nothing, otherwise up to the viewer's clearance on the author.
 */
final class DiaryVisibilityScope
{
    /** @param  Builder<Diary>  $query */
    public static function apply(Builder $query, ?Member $viewer, Member $owner): void
    {
        if ($viewer === null) {
            $query->where('visibility', '<=', Visibility::Open->value);
        } elseif (! $viewer->is($owner) && BlockLookup::ownerBlocksViewer($owner, $viewer)) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('visibility', '<=', Visibility::clearanceFor($viewer, $owner)->value);
        }
    }
}
