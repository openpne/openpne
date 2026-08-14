<?php

namespace App\Features\GroupTalk\Actions;

use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Exceptions\GroupTalkActionFailure;
use App\Features\GroupTalk\GroupTalkPermissions;
use App\Models\GroupMessage;
use App\Models\Member;

class DeleteGroupMessage
{
    /**
     * Retract a message. Physical, like every other deletion in the group boards — talk keeps no
     * tombstone, and the read cursor holds copied values rather than a reference, so a deleted
     * message leaves nothing dangling behind it.
     *
     * Collect the owned image Files before the row is gone: the FK cascade drops the join rows but
     * never the File bytes, which a disk backend deletes irreversibly. Purge them after the delete.
     * Its reactions go the same way, for a different reason — see {@see purge()}.
     *
     * @throws GroupTalkActionException
     */
    public function __invoke(Member $actor, GroupMessage $message): void
    {
        if (! GroupTalkPermissions::for($message->group, $actor)->canDelete($message)) {
            throw new GroupTalkActionException(GroupTalkActionFailure::CannotDelete);
        }

        $this->purge($message);
    }

    /**
     * Delete the message, its reactions and its image bytes — no authorization. Called directly
     * where the caller has already decided (a group being torn down); frontend callers go through
     * __invoke.
     *
     * The reactions are swept by hand because `reactions.reactable_id` is polymorphic and so carries
     * no foreign key; nothing would take them with the row. No version bump: the message the client
     * would be told to re-read is the one that has just stopped existing.
     */
    public function purge(GroupMessage $message): void
    {
        $files = $message->images()->with('file')->get()->pluck('file')->filter()->all();

        $message->reactions()->reorder()->delete();
        $message->delete();

        foreach ($files as $file) {
            $file->delete(); // deleting the File purges its bytes
        }
    }
}
