<?php

namespace App\Features\Community\Actions;

use App\Features\Community\CommunityMembership;
use App\Features\Community\Exceptions\CommunityActionException;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Models\Community;
use App\Models\Member;

class DeleteCommunity
{
    public function __invoke(Member $actor, Community $community): void
    {
        if (! CommunityMembership::isAdmin($community, $actor)) {
            throw new CommunityActionException(CommunityActionFailure::NotAdmin);
        }

        // FK cascade removes memberships and join requests. The top-image File purge lands with
        // the image slice (no community image exists yet).
        $community->delete();
    }
}
