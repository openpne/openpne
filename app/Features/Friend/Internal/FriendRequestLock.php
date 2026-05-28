<?php

namespace App\Features\Friend\Internal;

use App\Models\Member;
use Illuminate\Support\Facades\DB;

class FriendRequestLock
{
    public static function acquire(Member $a, Member $b): void
    {
        $aId = $a->getKey();
        $bId = $b->getKey();

        DB::table('friend_requests')
            ->where(function ($q) use ($aId, $bId) {
                $q->where('requester_id', $aId)->where('target_id', $bId);
            })
            ->orWhere(function ($q) use ($aId, $bId) {
                $q->where('requester_id', $bId)->where('target_id', $aId);
            })
            ->lockForUpdate()
            ->get();
    }
}
