<?php

namespace App\Features\Diary;

use App\Features\Block\BlockLookup;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;

/**
 * The row-level counterpart of {@see DiaryVisibilityScope}, which has to answer the same rule
 * (docs/internals/feature-modules.md, "Authorization and visibility").
 */
final class DiaryAccess
{
    public static function canView(?Member $viewer, Diary $diary): bool
    {
        $owner = $diary->member;

        if ($viewer === null) {
            // The web-public switch is checked here, not only where a page is routed: a diary's
            // images are fetched by URL, so a controller-level gate would leave a known image URL
            // readable after the switch went off.
            return DiaryVisibility::allowsWebPublic()
                && $diary->visibility->value <= Visibility::Open->value;
        }

        if ($viewer->is($owner)) {
            return true;
        }

        if (BlockLookup::ownerBlocksViewer($owner, $viewer)) {
            return false;
        }

        return $diary->visibility->value <= Visibility::clearanceFor($viewer, $owner)->value;
    }
}
