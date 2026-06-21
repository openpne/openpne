<?php

namespace App\Features\Message\Actions;

use App\Features\Message\Exceptions\MessageActionException;
use App\Features\Message\Exceptions\MessageActionFailure;
use App\Features\Message\MessageAccess;
use App\Files\PostImages;
use App\Models\Member;
use App\Models\Message;
use App\Notifications\Message\MessageReceivedNotification;
use Illuminate\Http\UploadedFile;

/**
 * Edit one of the sender's own drafts: change its text, manage its image slots (OpenPNE 3-style:
 * remove selected images, add new ones into the freed slots), and either keep it a draft or send it.
 * The recipient is the one fixed when the draft was created. Image bytes are rollback-safe (new
 * uploads compensated on failure; removed bytes purged only after commit). Sending notifies the
 * recipient after commit.
 */
class UpdateDraft
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * @param  array<int, UploadedFile>  $newImages  images to add, into the lowest free slots
     * @param  array<int, int|string>  $removeImageIds  ids of this draft's images to remove
     */
    public function __invoke(Member $sender, Message $draft, string $subject, string $body, bool $asDraft, array $newImages = [], array $removeImageIds = []): Message
    {
        // The viewer's own, still a draft, and not trashed/purged (OpenPNE 3 isDraftOwner rejects a
        // deleted draft).
        abort_unless((int) $draft->sender_id === (int) $sender->getKey() && $draft->is_draft
            && $draft->sender_deleted_at === null && $draft->sender_purged_at === null, 404);

        $recipient = $draft->recipients()->with('recipient')->first()?->recipient;
        if (! $asDraft && ($recipient === null || ! MessageAccess::canSend($sender, $recipient))) {
            throw new MessageActionException(MessageActionFailure::CannotSend);
        }

        $removedFiles = $this->images->compensating(function (callable $store) use ($draft, $subject, $body, $asDraft, $newImages, $removeImageIds): array {
            // Serialize concurrent edits: the free-slot read and the inserts must not interleave with
            // another edit, or both could claim the same slot or push past the cap.
            Message::whereKey($draft->getKey())->lockForUpdate()->first();

            $draft->subject = $subject;
            $draft->body = $body;
            $draft->is_draft = $asDraft;
            $draft->save();

            // Drop the selected images (this draft's only). Keep their Files to purge after commit.
            $removed = $draft->files()->whereKey(array_unique($removeImageIds))->with('file')->get();
            $draft->files()->whereKey($removed->modelKeys())->delete();

            // Add the new uploads into the lowest free slots, rechecking the count under the lock.
            $used = $draft->files()->pluck('number')->all();
            $free = array_values(array_diff(range(1, PostImages::MAX_IMAGES), $used));
            if (count($newImages) > count($free)) {
                throw new MessageActionException(MessageActionFailure::TooManyImages);
            }
            foreach (array_values($newImages) as $index => $upload) {
                $file = $store($upload, 'message', (int) $draft->getKey());
                $draft->files()->create(['file_id' => $file->getKey(), 'number' => $free[$index]]);
            }

            return $removed->pluck('file')->filter()->values()->all();
        });

        foreach ($removedFiles as $file) {
            $file->delete(); // deleting the File purges its bytes
        }

        if (! $asDraft && $recipient !== null) {
            $recipient->notify(new MessageReceivedNotification($sender, $draft));
        }

        return $draft;
    }
}
