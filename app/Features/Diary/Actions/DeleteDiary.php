<?php

namespace App\Features\Diary\Actions;

use App\Features\Diary\DiaryThreadLock;
use App\Features\Diary\Exceptions\DiaryActionException;
use App\Features\Diary\Exceptions\DiaryActionFailure;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryCommentImage;
use App\Models\DiaryImage;
use App\Models\File;
use App\Models\Member;
use App\Models\Reaction;
use Illuminate\Support\Facades\DB;

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
     * No authorization: the `purge()` half of the Action split (docs/internals/feature-modules.md, "Surface responsibilities").
     * The cascade drops the comments and the `*_image` link rows but never the File bytes nor the
     * reactions, so both are collected under the diary lock; the reactions go inside the transaction
     * and the Files after it.
     */
    public function purge(Diary $diary): void
    {
        $files = DB::transaction(function () use ($diary): array {
            if (! DiaryThreadLock::hold($diary)) {
                return [];
            }

            $commentIds = $diary->comments()->sharedLock()->pluck('id')->all();

            Reaction::query()
                ->where('reactable_type', $diary->getMorphClass())
                ->where('reactable_id', $diary->getKey())
                ->delete();
            Reaction::query()
                ->where('reactable_type', (new DiaryComment)->getMorphClass())
                ->whereIn('reactable_id', $commentIds)
                ->delete();

            $files = $this->ownedImageFiles($diary, $commentIds);

            $diary->delete();

            return $files;
        });

        foreach ($files as $file) {
            $file->delete();
        }
    }

    /**
     * @param  list<int>  $commentIds
     * @return list<File>
     */
    private function ownedImageFiles(Diary $diary, array $commentIds): array
    {
        $files = DiaryImage::query()
            ->where('diary_id', $diary->getKey())
            ->with('file')
            ->get()
            ->pluck('file')
            ->all();

        foreach (DiaryCommentImage::query()->whereIn('diary_comment_id', $commentIds)->with('file')->get() as $image) {
            $files[] = $image->file;
        }

        return array_values(array_filter($files));
    }
}
