<?php

namespace App\Notifications\Message;

use App\Mail\Template\MailTemplate;
use App\Models\Member;
use App\Models\Message;
use App\Notifications\Concerns\RendersMailTemplate;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a recipient a new message arrived (OpenPNE 3 notifyNewMessage). Mail + database, each
 * gated by the recipient's catalog opt-in: MessageNew covers every sender; while it is off,
 * MessageNewOnlyFriends (its dependOnNot variant) still covers friend senders — the OpenPNE 3
 * opMessagePluginUtil if/elseif chain.
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
        $channels = $this->recipientWants($notifiable, NotificationChannel::Mail)
            ? $this->templateChannels(MailTemplate::MessageReceived)
            : [];

        if ($this->recipientWants($notifiable, NotificationChannel::Web)) {
            $channels[] = 'database';
        }

        return $channels;
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::MessageReceived, [
            'member' => ['name' => $this->sender->name],
            // OpenPNE 3 notifyNewMessage variable names, so imported wording renders as-is.
            'member_name' => $this->sender->name,
            'message_subject' => $this->message->subject,
            'message_body' => $this->message->body,
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

    private function recipientWants(Member $notifiable, NotificationChannel $channel): bool
    {
        if ($notifiable->wantsNotification(NotificationKind::MessageNew, $channel)) {
            return true;
        }

        return $notifiable->isFriendsWith($this->sender)
            && $notifiable->wantsNotification(NotificationKind::MessageNewOnlyFriends, $channel);
    }
}
