<?php

namespace App\Observers;

use App\Models\Member;
use Illuminate\Support\Facades\DB;

class MemberObserver
{
    /**
     * Purge the member's avatar File once the row is durably gone. The File has no DB foreign key to
     * the member (the owner link is polymorphic), so the member_images cascade drops only the link row
     * — deleting the File runs the FileObserver, which removes the bytes, instead of leaving them
     * orphaned in storage.
     *
     * The purge is deferred to DB::afterCommit because `deleting` fires BEFORE the SQL DELETE: purging
     * inline would destroy the bytes irreversibly even if the delete (or an FK cascade) then rolled
     * back. Outside a transaction afterCommit runs the callback immediately, so plain deletes are
     * unchanged; on rollback it is discarded and the File row + bytes survive. Accepted residue: a
     * crash between commit and the callback leaks the File row and bytes (the repo's leak-over-loss
     * preference), never the reverse.
     *
     * Read through a query (not the cached relation, which may be stale) and on `deleting` (not a
     * member_images observer) because a DB-level cascade deletes the link row without firing events.
     */
    public function deleting(Member $member): void
    {
        $file = $member->avatar()->with('file')->first()?->file;

        if ($file !== null) {
            DB::afterCommit(fn () => $file->delete()); // deleting the File runs FileObserver, which purges the bytes
        }
    }
}
