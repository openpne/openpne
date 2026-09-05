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

    /** No authorization: the `purge()` half of the Action split (docs/internals/feature-modules.md, "Surface responsibilities"). */
    public function purge(Diary $diary): void
    {
        // Collect the diary's and its comments' owned image Files before the cascade drops the
        // *_image link rows; their bytes (irreversible on a disk backend) are purged after the row
        // is gone.
        $files = $this->ownedImageFiles($diary);

        $diary->delete();

        foreach ($files as $file) {
            $file->delete();
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
