<?php

namespace App\Features\Timeline\Queries;

use App\Features\Timeline\TimelineAccess;
use App\Models\Member;
use App\Models\TimelinePost;

/**
 * A single timeline post permalink, gated by the viewer's clearance.
 *
 * OpenPNE 3 re-centers a permalink whose id is a reply (in_reply_to set) to its parent post and
 * opens the thread there; this returns the addressed post directly. Re-centering belongs with the
 * reply thread.
 */
class ShowTimelinePost
{
    public function __invoke(Member $viewer, int $postId): ?TimelinePost
    {
        $post = TimelinePost::with(['member', 'images.file'])->find($postId);

        if ($post === null) {
            return null;
        }

        return TimelineAccess::canView($viewer, $post) ? $post : null;
    }
}
