<?php

namespace App\Notifications\Message;

use App\Mail\Template\MailTemplate;
use App\Models\Member;
use App\Models\Message;
use App\Notifications\Concerns\RendersMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a recipient a new message arrived (OpenPNE 3 notifyNewMessage). Mail + database, like the
 * friend-request notification. Per-member opt-out (OpenPNE 3 is_send_message) lands with the
 * notification centre; until then this fires for every delivered message, as the friend
 * notifications already do.
 */
class MessageReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersMailTemplate;

    public function __construct(
        public readonly Member $sender,
        public readonly Message $message,
    ) {}

    /** @return list<string> */
    public function via(Member $notifiable): array
    {
        return $this->templateChannels(MailTemplate::MessageReceived, ['database']);
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::MessageReceived, [
            'member' => ['name' => $this->sender->name],
            'url' => route('message.receive.show', ['message' => $this->message->getKey()]),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'kind' => 'message_received',
            'sender_id' => $this->sender->getKey(),
            'message_id' => $this->message->getKey(),
        ];
    }
}
