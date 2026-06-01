<?php

namespace App\Observers;

use App\Models\Member;

class MemberObserver
{
    /**
     * Purge the member's images before the row goes. Their Files have no DB foreign
     * key to the member (the owner link is polymorphic), so the member_images cascade
     * drops only the link rows — deleting the Files here runs the FileObserver, which
     * removes the bytes, instead of leaving them orphaned in storage.
     *
     * Runs on `deleting` (not a member_images observer) because a DB-level cascade
     * deletes those rows without firing Eloquent events.
     */
    public function deleting(Member $member): void
    {
        foreach ($member->images()->get() as $image) {
            $image->file?->delete();
        }
    }
}
