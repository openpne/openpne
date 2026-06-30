<?php

namespace App\Features\Message\Actions;

use App\Features\Message\Exceptions\MessageActionException;
use App\Features\Message\Exceptions\MessageActionFailure;
use App\Features\Message\MessageAccess;
use App\Features\Message\MessageComposeData;
use App\Files\PostImages;
use App\Models\Member;
use App\Models\Message;
use App\Notifications\Message\MessageReceivedNotification;
use Illuminate\Http\UploadedFile;

/**
 * Compose a new message (a fresh message or a reply) and either send it or keep it as a draft.
 * Sending creates the receipt (message_recipients) and notifies the recipient after commit; a draft
 * has no receipt and holds its pending recipient in draft_recipient_id, so a draft is never the
 * recipient's. Editing the draft (UpdateDraft) materializes the receipt when it is finally sent.
 */
class SendMessage
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * @param  array<int, UploadedFile>  $images  attachments (slot 1..N), at most the upload cap
     */
    public function __invoke(Member $sender, MessageComposeData $data, bool $asDraft, array $images = []): Message
    {
        $recipient = Member::find($data->recipientId);
        // OpenPNE 3 404s a missing or self-addressed recipient before the form even renders.
        abort_if($recipient === null || $sender->is($recipient), 404);

        // A draft to a blocked/banned member is allowed (kept private); only sending is gated.
        if (! $asDraft && ! MessageAccess::canSend($sender, $recipient)) {
            throw new MessageActionException(MessageActionFailure::CannotSend);
        }

        $message = $this->images->attach(
            'message',
            $images,
            persist: function () use ($sender, $recipient, $data, $asDraft): Message {
                $message = Message::create([
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
            relation: fn (Message $m) => $m->files(),
        );

        if (! $asDraft) {
            // After the attach transaction commits, so the queued notification sees the rows.
            $recipient->notify(
                (new MessageReceivedNotification($sender, $message))
                    ->locale($recipient->locale ?? app()->getLocale()),
            );
        }

        return $message;
    }
}
