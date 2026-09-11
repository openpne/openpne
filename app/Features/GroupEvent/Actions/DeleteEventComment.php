<?php

namespace App\Features\GroupEvent\Actions;

use App\Features\Group\BoardBumpedAt;
use App\Features\GroupEvent\Exceptions\GroupEventActionException;
use App\Features\GroupEvent\Exceptions\GroupEventActionFailure;
use App\Features\GroupEvent\GroupEventAccess;
use App\Models\GroupEventComment;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class DeleteEventComment
{
    public function __invoke(Member $actor, GroupEventComment $comment): void
    {
        if (! GroupEventAccess::canDeleteComment($comment, $actor)) {
            throw new GroupEventActionException(GroupEventActionFailure::CannotDeleteComment);
        }

        $this->purge($comment);
    }

    /** No authorization: the `purge()` half of the Action split (docs/internals/feature-modules.md, "Surface responsibilities"). */
    public function purge(GroupEventComment $comment): void
    {
        // Collect the comment's owned image Files before the cascade drops the *_image link rows;
        // their bytes (irreversible on a disk backend) are purged after the row is gone.
        $files = $comment->images()->with('file')->get()->pluck('file')->filter()->all();

        // Unlike OpenPNE 3, the event settles back to its last surviving comment; the numbers stay.
        DB::transaction(function () use ($comment): void {
            // Parent before comment row: the reverse order deadlocks against a event delete.
            $thread = $comment->event()->lockForUpdate()->firstOrFail();
            $comment->delete();
            BoardBumpedAt::settle($thread);
        });

        foreach ($files as $file) {
            $file->delete();
        }
    }
}
