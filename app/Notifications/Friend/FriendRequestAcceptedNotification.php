<?php

namespace App\Notifications\Friend;

use App\Mail\Template\MailTemplate;
use App\Models\Member;
use App\Notifications\Concerns\RendersMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FriendRequestAcceptedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersMailTemplate;

    public function __construct(public readonly Member $accepter) {}

    /** @return list<string> */
    public function via(Member $notifiable): array
    {
        return $this->templateChannels(MailTemplate::FriendAccepted, ['database']);
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::FriendAccepted, [
            'member' => ['name' => $this->accepter->name],
        ]);
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
