<?php

namespace App\Notifications\Friend;

use App\Mail\Template\MailTemplate;
use App\Models\Member;
use App\Notifications\Concerns\RendersMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FriendRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersMailTemplate;

    public function __construct(public readonly Member $requester) {}

    /** @return list<string> */
    public function via(Member $notifiable): array
    {
        return $this->templateChannels(MailTemplate::FriendRequested, ['database']);
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::FriendRequested, [
            'member' => ['name' => $this->requester->name],
            'url' => route('friend.manage'),
        ]);
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
