<?php

namespace App\Features\Timeline\Actions;

use App\Models\Member;
use App\Models\TimelinePost;

class CreateReply
{
    /**
     * Reply to a top-level post (the controller gates viewability and re-centers to the thread
     * root, so $parent is always top-level). A reply is a post row with in_reply_to_id set; it
     * carries no image (OpenPNE 3 parity) and inherits the parent's visibility so the whole thread
     * is gated as one audience.
     */
    public function __invoke(Member $author, TimelinePost $parent, string $body): TimelinePost
    {
        return TimelinePost::create([
            'member_id' => $author->getKey(),
            'in_reply_to_id' => $parent->getKey(),
            'body' => $body,
            'visibility' => $parent->visibility,
        ]);
    }
}
