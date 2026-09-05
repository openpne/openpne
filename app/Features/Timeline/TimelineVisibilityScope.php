<?php

namespace App\Features\Timeline;

use App\Features\Block\BlockLookup;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * The query counterpart of {@see TimelineAccess}, which has to answer the same rule
 * (docs/internals/feature-modules.md, "Authorization and visibility").
 */
final class TimelineVisibilityScope
{
    /** @param  Builder<TimelinePost>  $query */
    public static function apply(Builder $query, ?Member $viewer, Member $owner): void
    {
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
