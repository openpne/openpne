<?php

namespace App\Features\GroupTalk\Actions;

use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Exceptions\GroupTalkActionFailure;
use App\Features\GroupTalk\GroupTalkPermissions;
use App\Features\GroupTalk\TalkWriteLock;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class DeleteGroupMessage
{
    /**
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
     * No authorization: the caller has already decided, as the group teardown has. Nothing bumps the
     * reaction version — the message a client would be told to re-read has just stopped existing.
     */
    public function purge(GroupMessage $message): void
    {
        $files = DB::transaction(function () use ($message): array {
            if (! TalkWriteLock::hold($message)) {
                return [];
            }

            $files = $message->images()->with('file')->get()->pluck('file')->filter()->all();

            $message->reactions()->reorder()->delete();
            $message->delete();

            return $files;
        });

        foreach ($files as $file) {
            $file->delete();
        }
    }
}
