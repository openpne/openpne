<?php

namespace App\Features\Block\Actions;

use App\Features\Block\Exceptions\BlockActionException;
use App\Features\Block\Exceptions\BlockActionFailure;
use App\Features\Friend\FriendRequestLock;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class BlockMember
{
    public function __invoke(Member $blocker, Member $target): void
    {
        if ($blocker->is($target)) {
            throw new BlockActionException(BlockActionFailure::SelfBlock);
        }

        DB::transaction(function () use ($blocker, $target) {
            // Serializes against concurrent friend-request mutations for this pair:
            // Send/Accept take the same lock and re-check block state after acquiring
            // it, so the cleanup below cannot interleave with them. (Does not lock
            // member_blocks itself; the PK + insertOrIgnore handles duplicate blocks.)
            FriendRequestLock::acquire($blocker, $target);

            $inserted = DB::table('member_blocks')->insertOrIgnore([
                'blocker_id' => $blocker->getKey(),
                'blocked_id' => $target->getKey(),
            ]);

            if ($inserted === 0) {
                throw new BlockActionException(BlockActionFailure::AlreadyBlocked);
            }

            $this->severFriendGraph($blocker, $target);
        });
    }

    /** Blocking removes any friendship and cancels pending requests in both directions. */
    private function severFriendGraph(Member $blocker, Member $target): void
    {
        $aId = $blocker->getKey();
        $bId = $target->getKey();

        DB::table('friendships')
            ->where(function ($q) use ($aId, $bId) {
                $q->where('member_id', $aId)->where('friend_id', $bId);
            })
            ->orWhere(function ($q) use ($aId, $bId) {
                $q->where('member_id', $bId)->where('friend_id', $aId);
            })
            ->delete();

        DB::table('friend_requests')
            ->where(function ($q) use ($aId, $bId) {
                $q->where('requester_id', $aId)->where('target_id', $bId);
            })
            ->orWhere(function ($q) use ($aId, $bId) {
                $q->where('requester_id', $bId)->where('target_id', $aId);
            })
            ->delete();
    }
}
