<?php

namespace App\Features\Friend;

use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Block mutations touch the same pair, so they take this lock too. The pair is locked as (low id, high
 * id) whichever way the caller passes it, so two writers never wait on each other in opposite order.
 */
class FriendRequestLock
{
    public static function acquire(Member $a, Member $b): void
    {
        $aId = $a->getKey();
        $bId = $b->getKey();
        [$lo, $hi] = $aId < $bId ? [$aId, $bId] : [$bId, $aId];

        DB::table('friend_requests')
            ->where(function ($q) use ($lo, $hi) {
                $q->where('requester_id', $lo)->where('target_id', $hi);
            })
            ->orWhere(function ($q) use ($lo, $hi) {
                $q->where('requester_id', $hi)->where('target_id', $lo);
            })
            ->lockForUpdate()
            ->get();
    }
}
