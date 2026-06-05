<?php

namespace App\Observers;

use App\Models\Member;

class MemberObserver
{
    /**
     * Purge the member's avatar File before the row goes. The File has no DB foreign
     * key to the member (the owner link is polymorphic), so the member_images cascade
     * drops only the link row — deleting the File here runs the FileObserver, which
     * removes the bytes, instead of leaving them orphaned in storage.
     *
     * Read through a query (not the cached relation, which may be stale) and on
     * `deleting` (not a member_images observer) because a DB-level cascade deletes the
     * link row without firing Eloquent events.
     */
    public function deleting(Member $member): void
    {
        $member->avatar()->with('file')->first()?->file?->delete();
    }
}
