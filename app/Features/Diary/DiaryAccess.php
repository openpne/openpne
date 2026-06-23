<?php

namespace App\Features\Diary;

use App\Features\Block\BlockLookup;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;

/**
 * Whether a viewer may read a single diary — the row-level counterpart of DiaryVisibilityScope
 * (which constrains a query). Same rule: a guest (no Member) sees only web-public, a blocked
 * viewer sees nothing, otherwise up to the viewer's clearance on the author. ShowDiary and
 * FilePolicy (diary image access) share this so the two never drift.
 */
final class DiaryAccess
{
    public static function canView(?Member $viewer, Diary $diary): bool
    {
        $owner = $diary->member;

        if ($viewer === null) {
            return $diary->visibility->value <= Visibility::Open->value;
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
