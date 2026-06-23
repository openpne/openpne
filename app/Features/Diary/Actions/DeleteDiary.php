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

        // Collect every owned image File before the row is gone: the FK cascade drops the
        // diary_image link rows but never the File bytes, which a disk backend deletes
        // irreversibly. Purge them after the diary is deleted (post-commit).
        $files = $this->ownedImageFiles($diary);

        $diary->delete(); // FK cascade removes comments and all diary_image link rows

        foreach ($files as $file) {
            $file->delete(); // deleting the File purges its bytes
        }
    }

    /** @return array<int, File> */
    private function ownedImageFiles(Diary $diary): array
    {
        return array_values(array_filter(
            $diary->images()->with('file')->get()->pluck('file')->all()
        ));
    }
}
