<?php

namespace App\Features\DirectMessage\Actions;

use App\Features\DirectMessage\DirectMessageAccess;
use App\Features\DirectMessage\DirectMessageComposeData;
use App\Features\DirectMessage\Exceptions\DirectMessageActionException;
use App\Features\DirectMessage\Exceptions\DirectMessageActionFailure;
use App\Files\PostImages;
use App\Models\DirectMessage;
use App\Models\Member;
use App\Notifications\DirectMessage\DirectMessageReceivedNotification;
use Illuminate\Http\UploadedFile;

/**
 * Compose a new message (a fresh message or a reply) and either send it or keep it as a draft.
 * Sending creates the receipt (direct_message_recipients) and notifies the recipient after commit; a draft
 * has no receipt and holds its pending recipient in draft_recipient_id, so a draft is never the
 * recipient's. Editing the draft (UpdateDraft) materializes the receipt when it is finally sent.
 */
class SendDirectMessage
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * @param  array<int, UploadedFile>  $images  attachments (slot 1..N), at most the upload cap
     */
    public function __invoke(Member $sender, DirectMessageComposeData $data, bool $asDraft, array $images = []): DirectMessage
    {
        $recipient = Member::find($data->recipientId);
        // OpenPNE 3 404s a missing or self-addressed recipient before the form even renders.
        abort_if($recipient === null || $sender->is($recipient), 404);

        // A draft to a blocked/banned member is allowed (kept private); only sending is gated.
        if (! $asDraft && ! DirectMessageAccess::canSend($sender, $recipient)) {
            throw new DirectMessageActionException(DirectMessageActionFailure::CannotSend);
        }

        $message = $this->images->attach(
            'directMessage',
            $images,
            persist: function () use ($sender, $recipient, $data, $asDraft): DirectMessage {
                $message = DirectMessage::create([
                    'sender_id' => $sender->getKey(),
                    // A draft keeps its recipient here; sending materializes a receipt instead.
                    'draft_recipient_id' => $asDraft ? $recipient->getKey() : null,
                    'subject' => $data->subject,
                    'body' => $data->body,
                    'is_draft' => $asDraft,
                    'parent_id' => $data->parentId,
                    'thread_id' => $data->threadId,
                ]);
                if (! $asDraft) {
                    $message->recipients()->create(['recipient_id' => $recipient->getKey()]);
                }

                return $message;
            },
            relation: fn (DirectMessage $m) => $m->files(),
        );

        if (! $asDraft) {
            // After the attach transaction commits, so the queued notification sees the rows.
            $recipient->notify(
                (new DirectMessageReceivedNotification($sender, $message))
                    ->locale($recipient->locale ?? app()->getLocale()),
            );
        }

        return $message;
    }
}
