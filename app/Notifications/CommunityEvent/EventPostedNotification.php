<?php

namespace App\Notifications\CommunityEvent;

use App\Mail\Template\MailTemplate;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\Member;
use App\Notifications\Concerns\RendersMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Announces a new event to a community member in the broadcast audience. The fan-out resolves each
 * recipient's channels once and passes them, so via() returns them verbatim (one instance per
 * recipient). Shares the community-posting mail template with the comment notifications.
 */
class EventPostedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersMailTemplate;

    /** @param list<string> $channels the pre-resolved delivery channels (mail and/or database). */
    public function __construct(
        public readonly Community $community,
        public readonly CommunityEvent $event,
        public readonly Member $author,
        public readonly array $channels,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::CommunityPostingNotified, [
            'community_name' => $this->community->name,
            'topic_name' => $this->event->name,
            'nickname' => $this->author->name,
            'body' => $this->event->body,
            'url' => route('communityEvent.show', ['event' => $this->event->getKey()]),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'community_event_posted',
            'author_id' => $this->author->getKey(),
            'event_id' => $this->event->getKey(),
            'community_id' => $this->community->getKey(),
        ];
    }
}
