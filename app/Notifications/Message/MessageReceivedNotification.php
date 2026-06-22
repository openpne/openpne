<?php

namespace App\Notifications\Message;

use App\Models\Member;
use App\Models\Message;
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

    public function __construct(
        public readonly Member $sender,
        public readonly Message $message,
    ) {}

    /** @return list<string> */
    public function via(Member $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return (new MailMessage)
            ->from(sns_admin_mail_address(), sns_name())
            ->subject('New message received')
            ->greeting("Hi {$notifiable->name},")
            ->line("{$this->sender->name} sent you a message.")
            ->action('Read the message', route('message.receive.show', ['message' => $this->message->getKey()]))
            ->salutation('— '.sns_name());
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
