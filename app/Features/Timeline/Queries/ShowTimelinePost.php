<?php

namespace App\Features\Timeline\Queries;

use App\Features\Timeline\TimelineAccess;
use App\Models\Member;
use App\Models\TimelinePost;

/**
 * A permalink addressing a reply re-centers to its parent, and the caller detects that by comparing
 * the returned key to the requested id.
 */
class ShowTimelinePost
{
    public function __invoke(Member $viewer, int $postId): ?TimelinePost
    {
        $post = TimelinePost::with(['member.avatar.file', 'images.file', 'linkCard.image', 'mentions', 'tags'])->find($postId);

        if ($post === null) {
            return null;
        }

        if ($post->in_reply_to_id !== null) {
            // The cascade keeps a reply's parent alive, so this re-fetch is defensive only.
            $post = TimelinePost::with(['member.avatar.file', 'images.file', 'linkCard.image', 'mentions', 'tags'])->find($post->in_reply_to_id);

            if ($post === null) {
                return null;
            }
        }

        return TimelineAccess::canView($viewer, $post) ? $post : null;
    }
}
