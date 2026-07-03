<?php

namespace App\Features\Friend\Queries;

use App\Models\Member;

/**
 * How many friend requests are awaiting this member's response (the received direction of
 * friend_requests). Drives the nav badge and the dashboard notice; the count only falls when the
 * member accepts or rejects, matching OpenPNE 3's "caution stays until you act on it" behavior.
 */
class CountReceivedFriendRequests
{
    public function __invoke(Member $viewer): int
    {
        return $viewer->friendRequestsReceived()->count();
    }
}
