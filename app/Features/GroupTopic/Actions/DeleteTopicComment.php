<?php

namespace App\Features\GroupTopic\Actions;

use App\Features\Group\BoardBumpedAt;
use App\Features\GroupTopic\Exceptions\GroupTopicActionException;
use App\Features\GroupTopic\Exceptions\GroupTopicActionFailure;
use App\Features\GroupTopic\GroupTopicAccess;
use App\Models\GroupTopicComment;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class DeleteTopicComment
{
    public function __invoke(Member $actor, GroupTopicComment $comment): void
    {
        if (! GroupTopicAccess::canDeleteComment($comment, $actor)) {
            throw new GroupTopicActionException(GroupTopicActionFailure::CannotDeleteComment);
        }

        $this->purge($comment);
    }

    /** No authorization: the `purge()` half of the Action split (docs/internals/feature-modules.md, "Surface responsibilities"). */
    public function purge(GroupTopicComment $comment): void
    {
        // Collect the comment's owned image Files before the cascade drops the *_image link rows;
        // their bytes (irreversible on a disk backend) are purged after the row is gone.
        $files = $comment->images()->with('file')->get()->pluck('file')->filter()->all();

        // Unlike OpenPNE 3, the topic settles back to its last surviving comment; the numbers stay.
        DB::transaction(function () use ($comment): void {
            $thread = $comment->topic()->lockForUpdate()->first();
            $comment->delete();
            BoardBumpedAt::settle($thread);
        });

        foreach ($files as $file) {
            $file->delete();
        }
    }
}
