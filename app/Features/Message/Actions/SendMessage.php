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
 * A receipt row is created either way — OpenPNE 3 stores the recipient on a draft too; it only
 * surfaces in the inbox once sent (is_draft=false). Sending notifies the recipient after commit.
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
                    'subject' => $data->subject,
                    'body' => $data->body,
                    'is_draft' => $asDraft,
                    'parent_id' => $data->parentId,
                    'thread_id' => $data->threadId,
                ]);
                $message->recipients()->create(['recipient_id' => $recipient->getKey()]);

                return $message;
            },
            relation: fn (Message $m) => $m->files(),
        );

        if (! $asDraft) {
            // After the attach transaction commits, so the queued notification sees the rows.
            $recipient->notify(new MessageReceivedNotification($sender, $message));
        }

        return $message;
    }
}
