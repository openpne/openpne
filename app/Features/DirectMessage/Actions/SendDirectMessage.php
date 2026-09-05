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
 * A draft has no receipt and holds its pending recipient in `draft_recipient_id`, so it is never the
 * recipient's; sending is what writes the receipt.
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
