<?php

namespace App\Features\Block;

use App\Models\Member;
use App\Support\ViewerRelations;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** See docs/internals/feature-modules.md, "Authorization and visibility". */
class BlockLookup
{
    public static function hasAnyBlockBetween(Member $a, Member $b): bool
    {
        $aId = $a->getKey();
        $bId = $b->getKey();

        return DB::table('member_blocks')
            ->where(function ($q) use ($aId, $bId) {
                $q->where('blocker_id', $aId)->where('blocked_id', $bId);
            })
            ->orWhere(function ($q) use ($aId, $bId) {
                $q->where('blocker_id', $bId)->where('blocked_id', $aId);
            })
            ->exists();
    }

    public static function ownerBlocksViewer(Member $owner, Member $viewer): bool
    {
        $known = app(ViewerRelations::class)->ownerBlocksViewer($owner, $viewer);

        if ($known !== null) {
            return $known;
        }

        return DB::table('member_blocks')
            ->where('blocker_id', $owner->getKey())
            ->where('blocked_id', $viewer->getKey())
            ->exists();
    }

    /**
     * Must answer as {@see ownerBlocksViewer()} does, or a feed lists a row whose own page 404s for
     * this viewer.
     *
     * @param  string  $ownerColumn  qualified owner-id column on the query's table (e.g. `diaries.member_id`)
     */
    public static function excludeOwnersBlockingViewer(Builder $query, Member $viewer, string $ownerColumn): void
    {
        $viewerId = $viewer->getKey();

        $query->whereNotExists(function (Builder $sub) use ($viewerId, $ownerColumn) {
            $sub->select(DB::raw(1))
                ->from('member_blocks')
                ->whereColumn('member_blocks.blocker_id', $ownerColumn)
                ->where('member_blocks.blocked_id', $viewerId);
        });
    }

    /**
     * Must answer as {@see hasAnyBlockBetween()} does, or a fan-out reaches a blocked pair.
     *
     * @param  string  $memberColumn  qualified member-id column on the query's table (e.g. `members.id`)
     */
    public static function excludeBlockedBetween(Builder $query, Member $other, string $memberColumn): void
    {
        $otherId = $other->getKey();

        $query->whereNotExists(function (Builder $sub) use ($otherId, $memberColumn) {
            $sub->select(DB::raw(1))
                ->from('member_blocks')
                ->where(function (Builder $q) use ($otherId, $memberColumn) {
                    $q->whereColumn('member_blocks.blocker_id', $memberColumn)->where('member_blocks.blocked_id', $otherId);
                })
                ->orWhere(function (Builder $q) use ($otherId, $memberColumn) {
                    $q->where('member_blocks.blocker_id', $otherId)->whereColumn('member_blocks.blocked_id', $memberColumn);
                });
        });
    }
}
