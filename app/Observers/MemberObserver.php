<?php

namespace App\Observers;

use App\Features\AiAccount\MemberTokens;
use App\Files\MemberFilePurgeException;
use App\Models\File;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Throwable;

class MemberObserver
{
    /**
     * Purged from `deleted` through DB::afterCommit, so the bytes go only once the DELETE has
     * succeeded and committed, and a rollback discards the callback. A crash between the commit and
     * the callback leaks the File rows and bytes, never the reverse (leak over loss).
     */
    public function deleted(Member $member): void
    {
        // Queried by owner, not through member_images: no FK reaches a polymorphically owned File.
        $ownerType = $member->getMorphClass();
        $ownerId = (int) $member->getKey();
        DB::afterCommit(fn () => $this->purgeFiles($ownerType, $ownerId));
    }

    public function deleting(Member $member): void
    {
        // No cascade reaches the polymorphic tokens, and a reused member id would hand a surviving
        // token to whoever inherits the id; deleted inline, not afterCommit, so a rollback restores them.
        MemberTokens::deleteAll($member);

        // Same id-reuse hazard (a surviving subscription would push the next id holder's notifications
        // to a stranger's browser); only rows addressed to this member go, since rows where they are
        // merely the actor keep other members' feeds rendering.
        $member->notifications()->delete();
        $member->pushSubscriptions()->delete();
    }

    /**
     * Every file the member owns goes, since a reused id would hand a survivor to the next holder (docs/internals/file-storage.md, "Ownerless files").
     * Never thrown: it runs inside WithdrawMember's commit, ahead of the audit record and the withdrawal mail.
     */
    private function purgeFiles(string $ownerType, int $ownerId): void
    {
        try {
            File::query()
                ->where('related_entity_type', $ownerType)
                ->where('related_entity_id', $ownerId)
                ->lazyById(100)
                ->each(function (File $file) use ($ownerId): void {
                    try {
                        $file->delete();
                    } catch (Throwable $e) {
                        report(new MemberFilePurgeException("File [{$file->getKey()}] of deleted member [{$ownerId}] was not deleted.", previous: $e));
                    }
                });
        } catch (Throwable $e) {
            report(new MemberFilePurgeException("Files of deleted member [{$ownerId}] may be left.", previous: $e));
        }
    }
}
