<?php

namespace App\Features\Timeline\Queries;

use App\Features\Timeline\TimelineAccess;
use App\Models\Member;
use App\Models\TimelinePost;

/**
 * The thread root for a timeline post permalink, gated by the viewer's clearance. OpenPNE 3 opens
 * the thread at the top-level post, so a permalink addressing a reply (in_reply_to set) re-centers
 * to its parent; the caller detects the re-center by comparing the returned key to the requested id.
 */
class ShowTimelinePost
{
    public function __invoke(Member $viewer, int $postId): ?TimelinePost
    {
        $post = TimelinePost::with(['member', 'images.file'])->find($postId);

        if ($post === null) {
            return null;
        }

        if ($post->in_reply_to_id !== null) {
            // The cascade keeps a reply's parent alive, so this re-fetch is defensive only.
            $post = TimelinePost::with(['member', 'images.file'])->find($post->in_reply_to_id);

            if ($post === null) {
                return null;
            }
        }

        return TimelineAccess::canView($viewer, $post) ? $post : null;
    }
}
