<?php

namespace App\Notifications\Friend;

use App\Models\Member;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FriendRequestAcceptedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Member $accepter) {}

    /** @return list<string> */
    public function via(Member $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return (new MailMessage)
            ->from(sns_admin_mail_address(), sns_name())
            ->subject('Friend request accepted')
            ->greeting("Hi {$notifiable->name},")
            ->line("{$this->accepter->name} accepted your friend request.")
            ->action('See your friends', route('friend.list'))
            ->salutation('— '.sns_name());
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'kind' => 'friend_request_accepted',
            'accepter_id' => $this->accepter->getKey(),
        ];
    }
}
