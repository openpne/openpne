<?php

namespace App\Features\Timeline\Queries;

use App\Features\Timeline\TimelineAccess;
use App\Models\Member;
use App\Models\TimelinePost;

/**
 * A single timeline post permalink, gated by the viewer's clearance.
 *
 * OpenPNE 3 re-centers a permalink whose id is a reply (in_reply_to set) to its parent post and
 * opens the thread there. A1 has no way to create replies (the reply slice is A4), so no reply
 * rows exist yet and this returns the addressed row directly; the parent re-centering lands with
 * the reply thread in A4.
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
