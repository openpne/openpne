<?php

namespace App\Features\Diary\Actions;

use App\Features\Diary\Data\DiaryFormData;
use App\Features\Diary\Exceptions\DiaryActionException;
use App\Features\Diary\Exceptions\DiaryActionFailure;
use App\Files\ImageEdit;
use App\Files\PostImages;
use App\Jobs\SyncLinkCard;
use App\Models\Diary;
use App\Models\Member;
use App\Support\BodyFormat;

class UpdateDiary
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * Image bytes are rollback-safe: new uploads are compensated if the transaction fails, and
     * removed images' bytes are purged only after commit.
     */
    public function __invoke(Member $actor, Diary $diary, DiaryFormData $data, ImageEdit $images): void
    {
        if (! $actor->is($diary->member)) {
            throw new DiaryActionException(DiaryActionFailure::NotAuthor);
        }

        $removedFiles = $this->images->compensating(function (callable $store) use ($diary, $data, $images): array {
            // Serialize concurrent edits of this diary: the free-slot read below and the inserts must
            // not interleave with another edit, or both could claim the same slot (number is not
            // unique) or push past the image cap.
            Diary::whereKey($diary->getKey())->lockForUpdate()->first();

            $attributes = [
                'title' => $data->title,
                'body' => $data->body,
                'visibility' => $data->visibility,
            ];
            // An op3 body keeps its format regardless of input: op3 is a migration-only format with no
            // author-facing editor, so an edit must never convert it (invariant, not just validation).
            if ($data->format !== null && $diary->format !== BodyFormat::Op3) {
                $attributes['format'] = $data->format;
            }
            // Filled and cleared before saving, so the card is detached in the same write as the
            // body it was derived from — a reader in between would otherwise see the new text under
            // the old card.
            $diary->fill($attributes);
            $diary->clearLinkCardIfBodyChanged();
            $diary->save();

            // This diary's images only — an id from another diary is ignored — and their Files are
            // purged after commit.
            $removed = $diary->images()->whereKey($images->removals)->with('file')->get();
            $diary->images()->whereKey($removed->modelKeys())->delete();

            // Recheck the count under the lock: the request validated against the pre-lock state,
            // so a concurrent edit could leave too few slots.
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

        SyncLinkCard::for($diary);

        foreach ($removedFiles as $file) {
            $file->delete();
        }
    }
}
