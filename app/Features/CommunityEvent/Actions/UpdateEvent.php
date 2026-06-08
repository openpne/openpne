<?php

namespace App\Features\CommunityEvent\Actions;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityEvent\Data\CommunityEventFormData;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionException;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionFailure;
use App\Files\PostImages;
use App\Models\CommunityEvent;
use App\Models\Member;
use Illuminate\Http\UploadedFile;

class UpdateEvent
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * Edit an event's fields and, OpenPNE 3-style, manage its image slots: remove the images in
     * $removeImageIds and add $newImages into the freed slots (1..MAX). Image bytes are rollback-safe
     * — new uploads are compensated if the transaction fails, and removed images' bytes (irreversible
     * on a disk backend) are purged only after commit.
     *
     * @param  array<int, UploadedFile>  $newImages  images to add, into the lowest free slots
     * @param  array<int, int|string>  $removeImageIds  ids of this event's images to remove
     */
    public function __invoke(Member $actor, CommunityEvent $event, CommunityEventFormData $data, array $newImages = [], array $removeImageIds = []): CommunityEvent
    {
        if (! CommunityEventAccess::canEditEvent($event, $actor)) {
            throw new CommunityEventActionException(CommunityEventActionFailure::CannotEdit);
        }

        $removedFiles = $this->images->compensating(function (callable $store) use ($event, $data, $newImages, $removeImageIds): array {
            // Serialize concurrent edits of this event: the free-slot read below and the inserts must
            // not interleave with another edit, or both could claim the same slot (number is not
            // unique) or push past the image cap.
            CommunityEvent::whereKey($event->getKey())->lockForUpdate()->first();

            // OpenPNE 3 bumps event_updated_at only when the name or body actually changes (preSave
            // isEventModified). The save bumps updated_at too (the board ordering key) whenever any
            // field changed, so an edited event rises on the board.
            $contentChanged = $event->name !== $data->name || $event->body !== $data->body;
            $event->fill([
                'name' => $data->name,
                'body' => $data->body,
                'open_date' => $data->open_date,
                'open_date_comment' => $data->open_date_comment,
                'area' => $data->area,
                'application_deadline' => $data->application_deadline,
                'capacity' => $data->capacity,
            ]);
            if ($contentChanged) {
                $event->event_updated_at = now();
            }
            $event->save();

            // Drop the selected images (this event's only — an id from another event is ignored).
            // Keep their Files to purge after commit; here only the link row is removed.
            $removed = $event->images()->whereKey(array_unique($removeImageIds))->with('file')->get();
            $event->images()->whereKey($removed->modelKeys())->delete();

            // Add the new uploads into the lowest free slots. Recheck the count under the lock: the
            // request validated against the pre-lock state, so a concurrent edit (or a crafted
            // payload that slipped the cross-field check) could leave too few slots — fail cleanly
            // rather than index past $free.
            $used = $event->images()->pluck('number')->all();
            $free = array_values(array_diff(range(1, PostImages::MAX_IMAGES), $used));
            if (count($newImages) > count($free)) {
                throw new CommunityEventActionException(CommunityEventActionFailure::TooManyImages);
            }
            foreach (array_values($newImages) as $index => $upload) {
                $file = $store($upload, 'communityEvent', (int) $event->getKey());
                $event->images()->create(['file_id' => $file->getKey(), 'number' => $free[$index]]);
            }

            return $removed->pluck('file')->filter()->values()->all();
        });

        foreach ($removedFiles as $file) {
            $file->delete(); // deleting the File purges its bytes
        }

        return $event;
    }
}
