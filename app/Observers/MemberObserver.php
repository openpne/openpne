<?php

namespace App\Observers;

use App\Models\Member;
use Illuminate\Support\Facades\DB;

class MemberObserver
{
    /**
     * Deferred to DB::afterCommit because `deleting` fires before the SQL DELETE: an inline purge
     * would destroy the bytes irreversibly even if the delete then rolled back, while a discarded
     * callback leaves the File row and bytes intact. A crash between the commit and the callback
     * leaks the File row and bytes, never the reverse (leak over loss).
     */
    public function deleting(Member $member): void
    {
        // A fresh query on `deleting`, not a member_images observer: a DB-level cascade deletes the
        // link row without firing events, and the cached relation may be stale.
        $file = $member->avatar()->with('file')->first()?->file;

        if ($file !== null) {
            DB::afterCommit(fn () => $file->delete()); // no FK reaches the File (polymorphic owner), so only this purges its bytes
        }

        // No cascade reaches the polymorphic tokens, and a reused member id would hand a surviving
        // token to whoever inherits the id; deleted inline, not afterCommit, so a rollback restores them.
        $member->tokens()->delete();

        // Same id-reuse hazard (a surviving subscription would push the next id holder's notifications
        // to a stranger's browser); only rows addressed to this member go, since rows where they are
        // merely the actor keep other members' feeds rendering.
        $member->notifications()->delete();
        $member->pushSubscriptions()->delete();
    }
}
