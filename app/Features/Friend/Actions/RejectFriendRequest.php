<?php

namespace App\Features\Friend\Actions;

use App\Features\Friend\Exceptions\FriendActionException;
use App\Features\Friend\Exceptions\FriendActionFailure;
use App\Features\Friend\FriendRequestLock;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class RejectFriendRequest
{
    public function __invoke(Member $rejecter, Member $requester): void
    {
        DB::transaction(function () use ($rejecter, $requester) {
            FriendRequestLock::acquire($rejecter, $requester);

            $deleted = DB::table('friend_requests')
                ->where('requester_id', $requester->getKey())
                ->where('target_id', $rejecter->getKey())
                ->delete();

            if ($deleted === 0) {
                throw new FriendActionException(FriendActionFailure::RequestNotFound);
            }
        });
    }
}
