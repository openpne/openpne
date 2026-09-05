<?php

namespace App\Features\Friend\Queries;

use App\Models\Member;

/** The count falls only when the member accepts or rejects, as OpenPNE 3's caution did. */
class CountReceivedFriendRequests
{
    public function __invoke(Member $viewer): int
    {
        return $viewer->friendRequestsReceived()->count();
    }
}
