<?php

namespace App\Features\Diary;

use App\Features\Block\BlockLookup;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * The query counterpart of {@see DiaryAccess}, which has to answer the same rule
 * (docs/internals/feature-modules.md, "Authorization and visibility").
 */
final class DiaryVisibilityScope
{
    /** @param  Builder<Diary>  $query */
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
     * The cross-author feed tier: every author's entries open to the membership at large, minus the
     * authors blocking the viewer. A guest gets the web-public tier only, and needs no block filter —
     * a guest is nobody to block.
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

    /** @param  Builder<Diary>  $query */
    private static function guestTier(Builder $query): void
    {
        if (! DiaryVisibility::allowsWebPublic()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('visibility', '<=', Visibility::Open->value);
    }
}
