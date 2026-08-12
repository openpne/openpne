<?php

namespace App\Features\Timeline;

use App\Features\Block\BlockLookup;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * Constrains a query over one author's timeline posts to what a viewer may see: a guest (no Member)
 * sees only web-public, a blocked viewer sees nothing, otherwise up to the viewer's clearance on
 * the author.
 *
 * Community-scoped posts are dropped whatever the clearance: an author's own timeline is SNS-wide,
 * and a community post is reached only through that community (OpenPNE 3 read the member timeline
 * with `foreign_table IS NULL`). The community's own read gate is a different question, answered by
 * TimelineAccess / CommunityTimelineAccess rather than by the author's clearance.
 */
final class TimelineVisibilityScope
{
    /** @param  Builder<TimelinePost>  $query */
    public static function apply(Builder $query, ?Member $viewer, Member $owner): void
    {
        $query->whereNull('community_id');
        if ($viewer === null) {
            // Nothing at all once web-public posting is off — a post stored as Open stays stored,
            // it just stops being served (the query counterpart of TimelineAccess).
            $query->where('visibility', '<=', Visibility::Open->value);
            if (! TimelineVisibility::allowsWebPublic()) {
                $query->whereRaw('1 = 0');
            }
        } elseif (! $viewer->is($owner) && BlockLookup::ownerBlocksViewer($owner, $viewer)) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('visibility', '<=', Visibility::clearanceFor($viewer, $owner)->value);
        }
    }
}
