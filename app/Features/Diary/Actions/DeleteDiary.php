<?php

namespace App\Features\Diary\Actions;

use App\Features\Diary\Exceptions\DiaryActionException;
use App\Features\Diary\Exceptions\DiaryActionFailure;
use App\Models\Diary;
use App\Models\File;
use App\Models\Member;

class DeleteDiary
{
    public function __invoke(Member $actor, Diary $diary): void
    {
        if (! $actor->is($diary->member)) {
            throw new DiaryActionException(DiaryActionFailure::NotAuthor);
        }

        $this->purge($diary);
    }

    /**
     * Delete the diary and purge its (and its comments') image bytes — no authorization.
     * The admin moderation panel calls this directly: the panel's `admin` guard is an
     * AdminUser, not a Member, so it can't satisfy the author check in __invoke. Frontend
     * callers always go through __invoke; admin callers gate via the panel guard.
     */
    public function purge(Diary $diary): void
    {
        // Collect every owned image File (the diary's and its comments') before the row is gone:
        // the FK cascade drops the *_image link rows but never the File bytes, which a disk backend
        // deletes irreversibly. Purge them after the diary is deleted (post-commit).
        $files = $this->ownedImageFiles($diary);

        $diary->delete(); // FK cascade removes comments and all *_image link rows

        foreach ($files as $file) {
            $file->delete(); // deleting the File purges its bytes
        }
    }

    /** @return array<int, File> */
    private function ownedImageFiles(Diary $diary): array
    {
        $files = $diary->images()->with('file')->get()->pluck('file')->all();

        foreach ($diary->comments()->with('images.file')->get() as $comment) {
            foreach ($comment->images as $image) {
                $files[] = $image->file;
            }
        }

        return array_values(array_filter($files));
    }
}
