<?php

namespace App\Features\CommunityTopic\Actions;

use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Features\CommunityTopic\Data\CommunityTopicFormData;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionException;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionFailure;
use App\Files\ImageEdit;
use App\Files\PostImages;
use App\Models\CommunityTopic;
use App\Models\Member;
use App\Support\BodyFormat;

class UpdateTopic
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * Edit a topic's text and manage its image slots: remove the images in
     * $images->removals and add $images->additions into the freed slots (1..MAX). Image bytes are
     * rollback-safe — new uploads are compensated if the transaction fails, and removed images'
     * bytes (irreversible on a disk backend) are purged only after commit.
     */
    public function __invoke(Member $actor, CommunityTopic $topic, CommunityTopicFormData $data, ImageEdit $images): CommunityTopic
    {
        if (! CommunityTopicAccess::canEditTopic($topic, $actor)) {
            throw new CommunityTopicActionException(CommunityTopicActionFailure::CannotEdit);
        }

        $removedFiles = $this->images->compensating(function (callable $store) use ($topic, $data, $images): array {
            // Serialize concurrent edits of this topic: the free-slot read below and the inserts must
            // not interleave with another edit, or both could claim the same slot (number is not
            // unique) or push past the image cap.
            CommunityTopic::whereKey($topic->getKey())->lockForUpdate()->first();

            // OpenPNE 3 bumps topic_updated_at only when the name or body actually changes. The save
            // bumps updated_at too (the board ordering key), so an edited topic rises on the board.
            $contentChanged = $topic->name !== $data->name || $topic->body !== $data->body;
            $topic->name = $data->name;
            $topic->body = $data->body;
            // An op3 body keeps its format regardless of input: op3 is a migration-only format with no
            // author-facing editor, so an edit must never convert it (invariant, not just validation).
            if ($data->format !== null && $topic->format !== BodyFormat::Op3) {
                $topic->format = $data->format;
            }
            if ($contentChanged) {
                $topic->topic_updated_at = now();
            }
            $topic->save();

            // Drop the selected images (this topic's only — an id from another topic is ignored).
            // Keep their Files to purge after commit; here only the link row is removed.
            $removed = $topic->images()->whereKey($images->removals)->with('file')->get();
            $topic->images()->whereKey($removed->modelKeys())->delete();

            // Add the new uploads into the lowest free slots. Recheck the count under the lock: the
            // request validated against the pre-lock state, so a concurrent edit (or a crafted
            // payload that slipped the cross-field check) could leave too few slots — fail cleanly
            // rather than index past $free.
            $used = $topic->images()->pluck('number')->all();
            $free = array_values(array_diff(range(1, PostImages::MAX_IMAGES), $used));
            if (count($images->additions) > count($free)) {
                throw new CommunityTopicActionException(CommunityTopicActionFailure::TooManyImages);
            }
            foreach ($images->additions as $index => $upload) {
                $file = $store($upload, 'communityTopic', (int) $topic->getKey());
                $topic->images()->create(['file_id' => $file->getKey(), 'number' => $free[$index]]);
            }

            return $removed->pluck('file')->filter()->values()->all();
        });

        foreach ($removedFiles as $file) {
            $file->delete(); // deleting the File purges its bytes
        }

        return $topic;
    }
}
