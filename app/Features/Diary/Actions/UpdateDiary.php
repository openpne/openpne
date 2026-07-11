<?php

namespace App\Features\Diary\Actions;

use App\Features\Diary\Data\DiaryFormData;
use App\Features\Diary\Exceptions\DiaryActionException;
use App\Features\Diary\Exceptions\DiaryActionFailure;
use App\Files\ImageEdit;
use App\Files\PostImages;
use App\Models\Diary;
use App\Models\Member;

class UpdateDiary
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * Edit a diary's text and manage its image slots: remove the images in
     * $images->removals and add $images->additions into the freed slots (1..MAX). Image bytes are
     * rollback-safe — new uploads are compensated if the transaction fails, and removed images'
     * bytes (irreversible on a disk backend) are purged only after commit.
     */
    public function __invoke(Member $actor, Diary $diary, DiaryFormData $data, ImageEdit $images): void
    {
        if (! $actor->is($diary->member)) {
            throw new DiaryActionException(DiaryActionFailure::NotAuthor);
        }

        $removedFiles = $this->images->compensating(function (callable $store) use ($diary, $data, $images): array {
            // Serialize concurrent edits: the free-slot read and the inserts must not interleave
            // with another edit, or both could claim the same slot (number is not unique) or push
            // past the image cap.
            Diary::whereKey($diary->getKey())->lockForUpdate()->first();

            $diary->update([
                'title' => $data->title,
                'body' => $data->body,
                'visibility' => $data->visibility,
            ]);

            // Drop the selected images (this diary's only — an id from another diary is ignored).
            // Keep their Files to purge after commit; here only the link row is removed.
            $removed = $diary->images()->whereKey($images->removals)->with('file')->get();
            $diary->images()->whereKey($removed->modelKeys())->delete();

            // Add the new uploads into the lowest free slots. Recheck the count under the lock: the
            // request validated against the pre-lock state, so a concurrent edit could leave too few
            // slots — fail cleanly rather than index past $free.
            $used = $diary->images()->pluck('number')->all();
            $free = array_values(array_diff(range(1, PostImages::MAX_IMAGES), $used));
            if (count($images->additions) > count($free)) {
                throw new DiaryActionException(DiaryActionFailure::TooManyImages);
            }
            foreach ($images->additions as $index => $upload) {
                $file = $store($upload, 'diary', (int) $diary->getKey());
                $diary->images()->create(['file_id' => $file->getKey(), 'number' => $free[$index]]);
            }

            return $removed->pluck('file')->filter()->values()->all();
        });

        foreach ($removedFiles as $file) {
            $file->delete(); // deleting the File purges its bytes
        }
    }
}
