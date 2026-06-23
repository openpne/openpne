<?php

namespace App\Features\Timeline\Queries;

use App\Features\Timeline\TimelineAccess;
use App\Models\Member;
use App\Models\TimelinePost;

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
