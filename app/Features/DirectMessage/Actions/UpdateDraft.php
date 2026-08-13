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
 * Edit one of the sender's own drafts: change its text, manage its image slots (remove selected
 * images, add new ones into the freed slots), and either keep it a draft or send it.
 * The recipient is the one fixed when the draft was created (draft_recipient_id); sending materializes
 * its receipt and clears the column, so the message becomes delivered. Image bytes are rollback-safe
 * (new uploads compensated on failure; removed bytes purged only after commit). Sending notifies the
 * recipient after commit.
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
            // Re-read under the lock and re-check the fresh state — not the stale $draft read before the
            // lock. This serializes concurrent edits (so two adds can't claim the same image slot) and,
            // crucially, stops a double-submitted send: a racing send commits is_draft=false first, then
            // this one sees a non-draft and aborts instead of inserting a second receipt / notifying.
            $fresh = DirectMessage::whereKey($draft->getKey())->lockForUpdate()->first();
            abort_unless($fresh !== null && (int) $fresh->sender_id === (int) $sender->getKey()
                && $fresh->is_draft && $fresh->sender_deleted_at === null && $fresh->sender_purged_at === null, 404);

            $draft->subject = $subject;
            $draft->body = $body;
            $draft->is_draft = $asDraft;
            if (! $asDraft) {
                // Sending: the receipt below makes it delivered, so the draft-only column is cleared.
                $draft->draft_recipient_id = null;
            }
            $draft->save();
            if (! $asDraft) {
                $draft->recipients()->create(['recipient_id' => $recipient->getKey()]);
            }

            // Drop the selected images (this draft's only). Keep their Files to purge after commit.
            $removed = $draft->files()->whereKey($images->removals)->with('file')->get();
            $draft->files()->whereKey($removed->modelKeys())->delete();

            // Add the new uploads into the lowest free slots, rechecking the count under the lock.
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

        foreach ($removedFiles as $file) {
            $file->delete(); // deleting the File purges its bytes
        }

        if (! $asDraft && $recipient !== null) {
            $recipient->notify(
                (new DirectMessageReceivedNotification($sender, $draft))
                    ->locale($recipient->locale ?? app()->getLocale()),
            );
        }

        return $draft;
    }
}
