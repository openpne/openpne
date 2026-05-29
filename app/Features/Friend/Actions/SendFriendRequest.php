<?php

namespace App\Features\Friend\Actions;

use App\Features\Block\BlockLookup;
use App\Features\Friend\Events\FriendRequestAccepted;
use App\Features\Friend\Events\FriendRequested;
use App\Features\Friend\Exceptions\FriendActionException;
use App\Features\Friend\Exceptions\FriendActionFailure;
use App\Features\Friend\FriendRequestLock;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class SendFriendRequest
{
    public function __invoke(Member $requester, Member $target): void
    {
        if ($requester->is($target)) {
            throw new FriendActionException(FriendActionFailure::SelfFriendship);
        }

        DB::transaction(function () use ($requester, $target) {
            FriendRequestLock::acquire($requester, $target);

            if ($requester->isFriendsWith($target)) {
                throw new FriendActionException(FriendActionFailure::AlreadyFriends);
            }

            if (BlockLookup::hasAnyBlockBetween($requester, $target)) {
                throw new FriendActionException(FriendActionFailure::Blocked);
            }

            if ($requester->hasPendingRequestFrom($target)) {
                $this->autoAccept($requester, $target);

                return;
            }

            if ($target->hasPendingRequestFrom($requester)) {
                throw new FriendActionException(FriendActionFailure::DuplicateRequest);
            }

            DB::table('friend_requests')->insert([
                'requester_id' => $requester->getKey(),
                'target_id' => $target->getKey(),
            ]);

            FriendRequested::dispatch($requester, $target);
        });
    }

    private function autoAccept(Member $requester, Member $originalRequester): void
    {
        DB::table('friend_requests')
            ->where('requester_id', $originalRequester->getKey())
            ->where('target_id', $requester->getKey())
            ->delete();

        DB::table('friendships')->insert([
            ['member_id' => $requester->getKey(), 'friend_id' => $originalRequester->getKey()],
            ['member_id' => $originalRequester->getKey(), 'friend_id' => $requester->getKey()],
        ]);

        FriendRequestAccepted::dispatch($originalRequester, $requester);
    }
}
