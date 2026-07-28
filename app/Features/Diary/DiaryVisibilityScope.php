<?php

namespace App\Features\Diary;

use App\Features\Block\BlockLookup;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * Constrains a query over diaries to what a viewer may see, matching OpenPNE 3
 * getPublicFlagByMemberId + addPublicFlagQuery: a guest (no Member) sees only web-public, a
 * blocked viewer sees nothing, otherwise up to the viewer's clearance on the author.
 */
final class DiaryVisibilityScope
{
    /**
     * One author's diaries.
     *
     * @param  Builder<Diary>  $query
     */
    public static function apply(Builder $query, ?Member $viewer, Member $owner): void
    {
        if ($viewer === null) {
            self::guestTier($query);
        } elseif (! $viewer->is($owner) && BlockLookup::ownerBlocksViewer($owner, $viewer)) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('visibility', '<=', Visibility::clearanceFor($viewer, $owner)->value);
        }
    }

    /**
     * The cross-author feed tier (recent diaries, keyword search): every author's entries open to
     * the membership at large, minus the authors blocking the viewer. Open (web-public) sits below
     * Members on the monotonic scale, so it is included. A guest gets the web-public tier only, and
     * blocks need no filter — a guest is nobody to block.
     *
     * @param  Builder<Diary>  $query
     */
    public static function applyFeed(Builder $query, ?Member $viewer): void
    {
        if ($viewer === null) {
            self::guestTier($query);

            return;
        }

        $query->where('visibility', '<=', Visibility::Members->value);
        BlockLookup::excludeOwnersBlockingViewer($query, $viewer, 'diaries.member_id');
    }

    /**
     * What a signed-out visitor may read: web-public entries while the SNS serves them, nothing at
     * all once the switch is off (an entry stored as Open stays stored, it just stops being served).
     *
     * @param  Builder<Diary>  $query
     */
    private static function guestTier(Builder $query): void
    {
        if (! DiaryVisibility::allowsWebPublic()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('visibility', '<=', Visibility::Open->value);
    }
}
