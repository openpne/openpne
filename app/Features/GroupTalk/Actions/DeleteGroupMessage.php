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
     * Delete the message and purge its image bytes — no authorization. Called directly where the
     * caller has already decided (a group being torn down); frontend callers go through __invoke.
     */
    public function purge(GroupMessage $message): void
    {
        $files = $message->images()->with('file')->get()->pluck('file')->filter()->all();

        $message->delete();

        foreach ($files as $file) {
            $file->delete(); // deleting the File purges its bytes
        }
    }
}
