<?php

namespace App\Notifications\Friend;

use App\Models\Member;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FriendRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Member $requester) {}

    /** @return list<string> */
    public function via(Member $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return (new MailMessage)
            ->from(sns_admin_mail_address(), sns_name())
            ->subject('Friend request received')
            ->greeting("Hi {$notifiable->name},")
            ->line("{$this->requester->name} sent you a friend request.")
            ->action('Open pending requests', route('friend.manage'))
            ->salutation('— '.sns_name());
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'kind' => 'friend_requested',
            'requester_id' => $this->requester->getKey(),
        ];
    }
}
