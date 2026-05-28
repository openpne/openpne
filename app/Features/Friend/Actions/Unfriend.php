<?php

namespace App\Features\Friend\Actions;

use App\Features\Friend\Exceptions\FriendActionException;
use App\Features\Friend\Exceptions\FriendActionFailure;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class Unfriend
{
    public function __invoke(Member $member, Member $other): void
    {
        DB::transaction(function () use ($member, $other) {
            $aId = $member->getKey();
            $bId = $other->getKey();

            $deleted = DB::table('friendships')
                ->where(function ($q) use ($aId, $bId) {
                    $q->where('member_id', $aId)->where('friend_id', $bId);
                })
                ->orWhere(function ($q) use ($aId, $bId) {
                    $q->where('member_id', $bId)->where('friend_id', $aId);
                })
                ->delete();

            if ($deleted !== 2) {
                throw new FriendActionException(FriendActionFailure::NotFriends);
            }
        });
    }
}
