<?php

namespace App\Features\Friend\Queries;

use App\Models\Member;

/** Nothing ages the count out: it falls only when the request row goes, as OpenPNE 3's caution did. */
class CountReceivedFriendRequests
{
    public function __invoke(Member $viewer): int
    {
        return $viewer->friendRequestsReceived()->count();
    }
}
