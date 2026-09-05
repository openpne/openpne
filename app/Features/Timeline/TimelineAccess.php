<?php

namespace App\Features\Timeline;

use App\Features\Block\BlockLookup;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;

/**
 * The row-level counterpart of {@see TimelineVisibilityScope}, which has to answer the same rule
 * (docs/internals/feature-modules.md, "Authorization and visibility").
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
