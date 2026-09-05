<?php

namespace App\Notifications\GroupEvent;

use App\Features\Member\MemberDisplayName;
use App\Mail\Template\MailTemplate;
use App\Models\Group;
use App\Models\GroupEvent;
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
 * The fan-out resolves each recipient's channels once and passes them, so via() returns them verbatim and
 * gates nothing (docs/internals/notifications.md, Broadcast fan-out).
 */
class EventPostedNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature;
    use Queueable;
    use RendersMailTemplate;

    /** @param list<string> $channels */
    public function __construct(
        public readonly Group $group,
        public readonly GroupEvent $event,
        public readonly Member $author,
        public readonly array $channels,
    ) {}

    public static function feature(): Feature
    {
        return Feature::GroupEvent;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::GroupPostingNotified, [
            'community_name' => $this->group->name,
            'topic_name' => $this->event->name,
            'nickname' => MemberDisplayName::of($this->author),
            // Flatten to plain text: the mail is text/plain, so a Markdown body must not arrive as
            // literal `**bold**` and an op3 body must carry no <op:*> tags.
            'body' => BodyRenderer::plainText($this->event->body, $this->event->format),
            'url' => route('group.events.show', ['event' => $this->event->getKey()]),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'group_event_posted',
            'author_id' => $this->author->getKey(),
            'event_id' => $this->event->getKey(),
            'group_id' => $this->group->getKey(),
        ];
    }
}
