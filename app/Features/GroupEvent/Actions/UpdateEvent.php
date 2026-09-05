<?php

namespace App\Features\GroupEvent\Actions;

use App\Features\GroupEvent\Data\GroupEventFormData;
use App\Features\GroupEvent\Exceptions\GroupEventActionException;
use App\Features\GroupEvent\Exceptions\GroupEventActionFailure;
use App\Features\GroupEvent\GroupEventAccess;
use App\Files\ImageEdit;
use App\Files\PostImages;
use App\Jobs\SyncLinkCard;
use App\Models\GroupEvent;
use App\Models\Member;
use App\Support\BodyFormat;

class UpdateEvent
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * Image bytes are rollback-safe: new uploads are compensated if the transaction fails, and
     * removed images' bytes are purged only after commit.
     */
    public function __invoke(Member $actor, GroupEvent $event, GroupEventFormData $data, ImageEdit $images): GroupEvent
    {
        if (! GroupEventAccess::canEditEvent($event, $actor)) {
            throw new GroupEventActionException(GroupEventActionFailure::CannotEdit);
        }

        $removedFiles = $this->images->compensating(function (callable $store) use ($event, $data, $images): array {
            // Serialize concurrent edits of this event: the free-slot read below and the inserts must
            // not interleave with another edit, or both could claim the same slot (number is not
            // unique) or push past the image cap.
            GroupEvent::whereKey($event->getKey())->lockForUpdate()->first();

            // OpenPNE 3 bumps event_updated_at only when the name or body changes (preSave
            // isEventModified); the save bumps updated_at whenever any field did.
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
            // An op3 body keeps its format regardless of input: op3 is a migration-only format with no
            // author-facing editor, so an edit must never convert it (invariant, not just validation).
            if ($data->format !== null && $event->format !== BodyFormat::Op3) {
                $event->format = $data->format;
            }
            if ($contentChanged) {
                $event->event_updated_at = now();
            }
            // Detached in the same write as the body it was derived from, so a reader in between
            // never sees the new text under the old card.
            $event->clearLinkCardIfBodyChanged();
            $event->save();

            // This event's images only — an id from another event is ignored — and their Files are
            // purged after commit.
            $removed = $event->images()->whereKey($images->removals)->with('file')->get();
            $event->images()->whereKey($removed->modelKeys())->delete();

            // Recheck the count under the lock: the request validated against the pre-lock state,
            // so a concurrent edit could leave too few slots.
            $used = $event->images()->pluck('number')->all();
            $free = array_values(array_diff(range(1, PostImages::MAX_IMAGES), $used));
            if (count($images->additions) > count($free)) {
                throw new GroupEventActionException(GroupEventActionFailure::TooManyImages);
            }
            foreach ($images->additions as $index => $upload) {
                $file = $store($upload, 'groupEvent', (int) $event->getKey());
                $event->images()->create(['file_id' => $file->getKey(), 'number' => $free[$index]]);
            }

            return $removed->pluck('file')->filter()->values()->all();
        });

        foreach ($removedFiles as $file) {
            $file->delete();
        }

        SyncLinkCard::for($event);

        return $event;
    }
}
