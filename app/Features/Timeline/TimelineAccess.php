<?php

namespace App\Features\Timeline;

use App\Features\Block\BlockLookup;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;

/**
 * Whether a viewer may read a single timeline post — the row-level counterpart of
 * TimelineVisibilityScope (which constrains a query). Same rule: a guest (no Member) sees only
 * web-public, a blocked viewer sees nothing, otherwise up to the viewer's clearance on the
 * author. ShowTimelinePost and FilePolicy (post image access) share this so the two never drift.
 */
final class TimelineAccess
{
    public static function canView(?Member $viewer, TimelinePost $post): bool
    {
        $owner = $post->member;

        if ($viewer === null) {
            // The web-public setting is checked here, not only where a page is routed: a post's
            // images are fetched by URL through FilePolicy, which no timeline page mediates.
            return TimelineVisibility::allowsWebPublic()
                && $post->visibility->value <= Visibility::Open->value;
        }

        if ($viewer->is($owner)) {
            return true;
        }

        if (BlockLookup::ownerBlocksViewer($owner, $viewer)) {
            return false;
        }

        return $post->visibility->value <= Visibility::clearanceFor($viewer, $owner)->value;
    }
}
