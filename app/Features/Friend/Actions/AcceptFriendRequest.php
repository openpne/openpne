<?php

namespace App\Features\Friend\Actions;

use App\Features\Block\BlockLookup;
use App\Features\Friend\Events\FriendRequestAccepted;
use App\Features\Friend\Exceptions\FriendActionException;
use App\Features\Friend\Exceptions\FriendActionFailure;
use App\Features\Friend\FriendRequestLock;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class AcceptFriendRequest
{
    public function __invoke(Member $accepter, Member $requester): void
    {
        DB::transaction(function () use ($accepter, $requester) {
            FriendRequestLock::acquire($accepter, $requester);

            if (! $accepter->hasPendingRequestFrom($requester)) {
                throw new FriendActionException(FriendActionFailure::RequestNotFound);
            }

            if (BlockLookup::hasAnyBlockBetween($accepter, $requester)) {
                throw new FriendActionException(FriendActionFailure::Blocked);
            }

            DB::table('friend_requests')
                ->where('requester_id', $requester->getKey())
                ->where('target_id', $accepter->getKey())
                ->delete();

            DB::table('friendships')->insert([
                ['member_id' => $accepter->getKey(), 'friend_id' => $requester->getKey()],
                ['member_id' => $requester->getKey(), 'friend_id' => $accepter->getKey()],
            ]);

            FriendRequestAccepted::dispatch($requester, $accepter);
        });
    }
}
