<?php

namespace App\Features\Timeline;

use App\Features\Block\BlockLookup;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Feature;
use App\Support\Visibility;

/**
 * Whether a viewer may read a single timeline post — the row-level counterpart of
 * TimelineVisibilityScope (which constrains a query). Same rule: a guest (no Member) sees only
 * web-public, a blocked viewer sees nothing, otherwise up to the viewer's clearance on the
 * author. ShowTimelinePost and FilePolicy (post image access) share this so the two never drift.
 *
 * A community-scoped post is answered by its community instead, before visibility is read at all.
 * Storing Members on those rows is a write-side invariant, not the gate: a legacy or corrupt Open
 * value must not open a permalink or its image bytes to a guest.
 */
final class TimelineAccess
{
    public static function canView(?Member $viewer, TimelinePost $post): bool
    {
        $owner = $post->member;

        if ($post->community_id !== null) {
            return self::canViewGroupPost($viewer, $post);
        }

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

    /**
     * A community post: never web-public (no guest reads one, whatever the stored visibility), gone
     * with the community unit, then the community's own read gate and the author's block. The
     * author's current membership is not read here — OpenPNE 3 dropped an ex-member's posts from the
     * community feed but still served their permalink, and that split is kept.
     */
    private static function canViewGroupPost(?Member $viewer, TimelinePost $post): bool
    {
        if ($viewer === null || ! Feature::Group->enabled()) {
            return false;
        }

        if (! CommunityTimelineAccess::canViewTimeline($post->community, $viewer)) {
            return false;
        }

        $owner = $post->member;

        return $viewer->is($owner) || ! BlockLookup::ownerBlocksViewer($owner, $viewer);
    }
}
