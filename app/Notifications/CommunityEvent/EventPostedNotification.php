<?php

namespace App\Notifications\CommunityEvent;

use App\Mail\Template\MailTemplate;
use App\Models\CommunityEvent;
use App\Models\Group;
use App\Models\Member;
use App\Notifications\Concerns\GatedByFeature;
use App\Notifications\Concerns\RendersMailTemplate;
use App\Notifications\FeatureNotification;
use App\Support\BodyRenderer;
use App\Support\Feature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Announces a new event to a community member in the broadcast audience. The fan-out resolves each
 * recipient's channels once and passes them, so via() returns them verbatim (one instance per
 * recipient). Shares the group-posting mail template with the comment notifications.
 */
class EventPostedNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature;
    use Queueable;
    use RendersMailTemplate;

    /** @param list<string> $channels the pre-resolved delivery channels (mail and/or database). */
    public function __construct(
        public readonly Group $community,
        public readonly CommunityEvent $event,
        public readonly Member $author,
        public readonly array $channels,
    ) {}

    public static function feature(): Feature
    {
        return Feature::CommunityEvent;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::GroupPostingNotified, [
            'community_name' => $this->community->name,
            'topic_name' => $this->event->name,
            'nickname' => $this->author->name,
            // Flatten to plain text: the mail is text/plain, so a Markdown body must not arrive as
            // literal `**bold**` and an op3 body must carry no <op:*> tags.
            'body' => BodyRenderer::plainText($this->event->body, $this->event->format),
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
