<?php

namespace App\Features\Community\Actions;

use App\Features\Community\CommunityMembership;
use App\Features\Community\Exceptions\CommunityActionException;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Models\Community;
use App\Models\File;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class DeleteCommunity
{
    public function __invoke(Member $actor, Community $community): void
    {
        if (! CommunityMembership::isAdmin($community, $actor)) {
            throw new CommunityActionException(CommunityActionFailure::NotAdmin);
        }

        // The FK cascade removes memberships and join requests but never the top-image File bytes.
        // Read the image under the same lock as the delete so a concurrent edit that just replaced it
        // can't leave the new File orphaned (file_id is a mutable self-column — a stale read would
        // miss that edit's image). Purge the bytes after commit.
        $image = DB::transaction(function () use ($community): ?File {
            $locked = Community::whereKey($community->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                return null; // already deleted by a concurrent request
            }

            $file = $locked->image()->first();
            $locked->delete();

            return $file;
        });

        $image?->delete(); // deleting the File purges its bytes
    }
}
