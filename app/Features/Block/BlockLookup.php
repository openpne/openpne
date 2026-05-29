<?php

namespace App\Features\Block;

use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Visibility primitive owned by Block, consumed cross-feature (Friend now,
 * Diary later). Direction matters: a block is one-directional (blocker→blocked),
 * so callers pick the method that matches their gate.
 */
class BlockLookup
{
    /** Bidirectional interaction gate: is either party blocking the other? */
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

    /** One-directional visibility: does the owner block this viewer? */
    public static function ownerBlocksViewer(Member $owner, Member $viewer): bool
    {
        return DB::table('member_blocks')
            ->where('blocker_id', $owner->getKey())
            ->where('blocked_id', $viewer->getKey())
            ->exists();
    }
}
