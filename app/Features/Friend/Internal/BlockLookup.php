<?php

namespace App\Features\Friend\Internal;

use App\Models\Member;
use Illuminate\Support\Facades\DB;

class BlockLookup
{
    public static function hasAnyBetween(Member $a, Member $b): bool
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
        return DB::table('member_blocks')
            ->where('blocker_id', $owner->getKey())
            ->where('blocked_id', $viewer->getKey())
            ->exists();
    }
}
