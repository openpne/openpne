<?php

namespace App\Features\Timeline;

use App\Features\Block\BlockLookup;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * Constrains a query over one author's timeline posts to what a viewer may see — the single-owner
 * counterpart of the home feed's TimelineFeedScope (added with the feed route). Same rule as
 * DiaryVisibilityScope: a guest (no Member) sees only web-public, a blocked viewer sees nothing,
 * otherwise up to the viewer's clearance on the author.
 */
final class TimelineVisibilityScope
{
    /** @param  Builder<TimelinePost>  $query */
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
