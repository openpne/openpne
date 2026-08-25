<?php

namespace App\Features\Friend\Actions;

use App\Features\Block\BlockLookup;
use App\Features\Friend\Events\FriendRequestAccepted;
use App\Features\Friend\Exceptions\FriendActionException;
use App\Features\Friend\Exceptions\FriendActionFailure;
use App\Features\Friend\FriendRequestLock;
use App\Features\Friend\FriendRequestNotificationRows;
use App\Models\Member;
use App\Support\ViewerRelations;
use Illuminate\Support\Facades\DB;

class AcceptFriendRequest
{
    public function __construct(private readonly FriendRequestNotificationRows $feedRows) {}

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

            $this->feedRows->markAnswered((int) $accepter->getKey(), (int) $requester->getKey());

            // One timestamp for both halves of the mirror (see SendFriendRequest).
            $at = now();

            DB::table('friendships')->insert([
                ['member_id' => $accepter->getKey(), 'friend_id' => $requester->getKey(), 'created_at' => $at],
                ['member_id' => $requester->getKey(), 'friend_id' => $accepter->getKey(), 'created_at' => $at],
            ]);

            FriendRequestAccepted::dispatch($requester, $accepter);
        });

        ViewerRelations::flush();
    }
}
