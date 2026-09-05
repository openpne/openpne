<?php

namespace App\Features\DirectMessage\Actions;

use App\Features\DirectMessage\DirectMessageAccess;
use App\Features\DirectMessage\Exceptions\DirectMessageActionException;
use App\Features\DirectMessage\Exceptions\DirectMessageActionFailure;
use App\Files\ImageEdit;
use App\Files\PostImages;
use App\Models\DirectMessage;
use App\Models\Member;
use App\Notifications\DirectMessage\DirectMessageReceivedNotification;

/**
 * The recipient is the one fixed when the draft was created (`draft_recipient_id`); sending writes
 * its receipt and clears the column.
 */
class UpdateDraft
{
    public function __construct(private readonly PostImages $images) {}

    public function __invoke(Member $sender, DirectMessage $draft, string $subject, string $body, bool $asDraft, ImageEdit $images): DirectMessage
    {
        // The viewer's own, still a draft, and not trashed/purged (OpenPNE 3 isDraftOwner rejects a
        // deleted draft).
        abort_unless((int) $draft->sender_id === (int) $sender->getKey() && $draft->is_draft
            && $draft->sender_deleted_at === null && $draft->sender_purged_at === null, 404);

        $recipient = $draft->draftRecipient;
        if (! $asDraft && ($recipient === null || ! DirectMessageAccess::canSend($sender, $recipient))) {
            throw new DirectMessageActionException(DirectMessageActionFailure::CannotSend);
        }

        $removedFiles = $this->images->compensating(function (callable $store) use ($sender, $draft, $recipient, $subject, $body, $asDraft, $images): array {
            // Re-checked under the lock rather than from the state read before it: a racing double
            // submit commits `is_draft = false` first, so this one aborts instead of sending twice.
            $fresh = DirectMessage::whereKey($draft->getKey())->lockForUpdate()->first();
            abort_unless($fresh !== null && (int) $fresh->sender_id === (int) $sender->getKey()
                && $fresh->is_draft && $fresh->sender_deleted_at === null && $fresh->sender_purged_at === null, 404);

            $draft->subject = $subject;
            $draft->body = $body;
            $draft->is_draft = $asDraft;
            if (! $asDraft) {
                $draft->draft_recipient_id = null;
            }
            $draft->save();
            if (! $asDraft) {
                $draft->recipients()->create(['recipient_id' => $recipient->getKey()]);
            }

            // Only this draft's own image rows match, so a removal id naming another message drops nothing.
            $removed = $draft->files()->whereKey($images->removals)->with('file')->get();
            $draft->files()->whereKey($removed->modelKeys())->delete();

            // The free slots are recomputed under the same lock, so two concurrent adds cannot claim one.
            $used = $draft->files()->pluck('number')->all();
            $free = array_values(array_diff(range(1, PostImages::MAX_IMAGES), $used));
            if (count($images->additions) > count($free)) {
                throw new DirectMessageActionException(DirectMessageActionFailure::TooManyImages);
            }
            foreach ($images->additions as $index => $upload) {
                $file = $store($upload, 'directMessage', (int) $draft->getKey());
                $draft->files()->create(['file_id' => $file->getKey(), 'number' => $free[$index]]);
            }

            return $removed->pluck('file')->filter()->values()->all();
        });

        // After the commit: a disk backend's byte deletion cannot be rolled back.
        foreach ($removedFiles as $file) {
            $file->delete();
        }

        if (! $asDraft && $recipient !== null) {
            // After the transaction commits, so the queued notification sees the receipt.
            $recipient->notify(
                (new DirectMessageReceivedNotification($sender, $draft))
                    ->locale($recipient->locale ?? app()->getLocale()),
            );
        }

        return $draft;
    }
}
