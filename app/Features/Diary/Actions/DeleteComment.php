<?php

namespace App\Features\Diary\Actions;

use App\Features\Diary\DiaryThreadLock;
use App\Features\Diary\Exceptions\DiaryActionException;
use App\Features\Diary\Exceptions\DiaryActionFailure;
use App\Models\DiaryComment;
use App\Models\Member;
use App\Models\Reaction;
use Illuminate\Support\Facades\DB;

class DeleteComment
{
    public function __invoke(Member $actor, DiaryComment $comment): void
    {
        if (! $comment->isDeletableBy($actor)) {
            throw new DiaryActionException(DiaryActionFailure::NotAuthor);
        }

        $this->purge($comment);
    }

    /**
     * No authorization: the `purge()` half of the Action split (docs/internals/feature-modules.md, "Surface responsibilities").
     * The reactions are swept under the diary lock inside the transaction; the File bytes, irreversible on a disk backend, are purged after it.
     */
    public function purge(DiaryComment $comment): void
    {
        $files = DB::transaction(function () use ($comment): array {
            if (! DiaryThreadLock::hold($comment)) {
                return [];
            }

            Reaction::query()
                ->where('reactable_type', $comment->getMorphClass())
                ->where('reactable_id', $comment->getKey())
                ->delete();

            $files = $comment->images()->with('file')->get()->pluck('file')->filter()->values()->all();

            $comment->delete();

            return $files;
        });

        foreach ($files as $file) {
            $file->delete();
        }
    }
}
